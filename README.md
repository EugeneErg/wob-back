# WOB backend

Laravel 13 + PostgreSQL 16 + PHP 8.3. Google sign-in, a cloud library, and
player progress.

The game already told us what to build. `core/content.js` ends with a comment
showing the exact call that swaps its local content source for a server one;
`core/replays.js` does the same for run storage; `core/releases.js` computes a
Merkle fingerprint of every story and says, in a comment, that forged runs are
caught by re-simulating on a server rather than by the hash. This backend is the
other half of those seams, built in the order they become useful.

```bash
composer install
cp .env.example .env && php artisan key:generate

createuser wob --pwprompt && createdb -O wob wob && createdb -O wob wob_test
php artisan migrate

php artisan serve
composer check          # phpstan, then phpstan level 8 on the domain, then phpunit
```

Verified on PHP 8.3.6, Laravel 13.29, PostgreSQL 16.15: 41 tests, 196
assertions, phpstan clean. The feature suite runs against a real Postgres rather
than SQLite, because the schema leans on `jsonb` and on cascade behaviour that
SQLite would quietly fake.

## Layout

Four bounded contexts, each a slice of `src/` with its own layers and its own
service provider. Nothing central lists them all, which is what keeps any one of
them extractable later.

```
src/
  Shared/      aggregate root, domain events, clock, domain-to-HTTP error mapping
  Identity/    who is signing in
  Library/     stories, chapters, levels, assets
  Progress/    which levels a player has finished
```

Inside a context:

```
Domain/          plain PHP. No Laravel, no Eloquent, no HTTP.
Application/     one use case per class: a command and a handler
Infrastructure/  Postgres, Google, the container wiring
Presentation/    controllers, request validation, response shaping
```

Dependencies point inward, and only inward. The domain names what it needs —
`StoryRepository`, `ContentHasher`, `Clock` — and infrastructure supplies it. The
practical test is that `src/*/Domain` contains no `use Illuminate` and no `use
GuzzleHttp`, and that is worth keeping true.

## Story is the aggregate

The client keeps four flat lists joined by id. That is right for localStorage and
wrong for a server, because every rule that actually holds spans more than one
list:

- a chapter map may only pin levels of its own story;
- a path may only join levels that are on that same map;
- an exit may only lead to a chapter of this story;
- deleting a chapter drops the levels no other chapter uses, and clears the
  exits that used to lead into it.

`library.js` implements all four correctly today, as free functions over the
lists. On a server that shape falls apart: with an endpoint per table, those
rules have nowhere to live and get re-derived, differently, in each one. So
`Story` owns its chapters and levels, and is the only object allowed to change
any of it. It is also the transaction boundary and the locking boundary — one
story loaded, changed and saved as a unit, with one version number. Two authors
on two stories never contend.

Assets are a separate aggregate, not part of `Story`, because the shelf is
per-author and shared across stories; a story merely marks some of them hot. A
hot list is therefore a reference across an aggregate boundary, by id, and a hot
id may outlive the asset it names. That is deliberate. The client already filters
missing assets out of the palette, and the alternative — cascading a delete into
every story — makes deleting one asset a write across the whole library.

## The server does not understand entities

`level.entities` is stored and returned untouched. Not laziness: it is the same
knowledge boundary the game is built on. Entity folders are meant to become the
unit of delivery, loaded at runtime, so the day a level arrives holding a type
that shipped after this backend was deployed, the backend still has to store it
and hand it back. A server that validated entity data would be the one thing
standing between the game and new content.

What is checked is the envelope — id, type name, `data` is an object, a `parent`
names something in the same level. What is inside `data` is nobody's business
here.

One consequence runs through the code: entity data is carried as `stdClass`, and
`SaveLevel` re-reads it from the raw request body instead of the validated array.
PHP cannot tell a decoded `{}` from a decoded `[]`, and that difference changes
the content hash.

## Content hashes must match the client, exactly

`Fnv1aContentHasher` is a port of `fnv()` and `stable()` from `core/releases.js`.
Close enough is not a thing here: if PHP and JavaScript disagree by one
character, every story looks edited the moment it is uploaded, and later, every
run the server accepts is a run the client considers stale.

Three details are load-bearing and all three are easy to miss:

1. `charCodeAt()` walks UTF-16 code units, not bytes. Every built-in level has a
   Cyrillic title, so a byte-wise fold is wrong on the very first level.
2. `Math.imul` wraps at 32 bits; PHP integers are 64.
3. `JSON.stringify` and `json_encode` disagree about slashes, non-ASCII and
   whole floats — `1800.0` prints as `1800` in JavaScript.

`tests/Unit/Library/Fnv1aContentHasherTest.php` checks the port against vectors
produced by running the real client code over the shipped library
(`tools/hashes.mjs`). The canonical string is asserted before the hash, because
comparing eight hex characters tells you nothing about why they differ.

That test earned its keep on the first run. `usort` with `$a <=> $b` on two
arrays of code units looks like the obvious comparator and is wrong: PHP
compares arrays by COUNT before content, so `"id"` sorted ahead of `"entities"`
purely for being shorter, and every object with keys of differing length
serialised in the wrong order. Every hand-written scalar vector passed — their
keys happened to be the same length — and every real level failed. There is now
a test asserting the sort directly, not only through a fingerprint.

## Ids come from the editor

The editor works offline and mints ids the moment you press a button, and the
level graph points at them immediately. So the client id is the public identity
of a thing, unique per author, and the database keeps a UUID of its own for
foreign keys. Collisions are handled the way `importBundle()` already handles
them: rename the newcomer, rewrite the references, never overwrite.

Progress is keyed by the internal UUID rather than the public id, because two
authors can each own a level called `lvl-tower`.

## Writes carry a version

The editor is offline-first, so two devices can hold the same story. Every write
sends the version it was based on; a stale write gets `409` with both numbers,
not a silent overwrite of somebody's afternoon. Every write answers with the new
version so the client can keep editing without a re-read.

## Sessions, not tokens in the body

The client sends the credential Google Identity Services handed the browser, and
gets back an http-only session cookie. A token in the JSON body would have to be
stored where the page can read it, which means an injected script can read it
too.

The ID token is verified locally against Google's published keys rather than by
calling `tokeninfo` — a network round trip to a third party in the path of every
sign-in makes their outage ours. Two checks beyond the signature are the ones
people skip, and both matter: the audience must be *our* client id (a validly
signed token issued to a different application is still a valid token), and the
issuer must be Google. `GoogleIdTokenVerifierTest` signs its own tokens with a
throwaway RSA key and checks exactly that: a perfectly valid token for the wrong
audience is refused.

The JWKS cache is hand-written rather than Firebase's `CachedKeySet`, which
wants a PSR-6 item pool where Laravel's cache is PSR-16. Twenty lines beat
another dependency, and they make the rotation rule visible: an unknown key id
buys exactly one refetch, because Google rotates keys every few hours and a
token signed by a key minted since the last fetch is a cache miss, not a
forgery.

CSRF protection is on, since a cookie-authenticated API needs it. `POST
/api/auth/google` is the one exemption — it is what establishes the session a
token would come from, and it proves nothing about the caller's cookies anyway,
being authenticated by a Google credential an attacker cannot mint. Sanctum's
`statefulApi()` was the obvious way to wire this and is the wrong one here: it
makes statefulness conditional on an `Origin` header, which is right when the
same API also serves bearer tokens and only creates a silent failure mode when
it never does.

## API

Everything except content-by-hash needs a session.

| Method | Path | |
|---|---|---|
| POST | `/api/auth/google` | sign in with a Google credential |
| GET | `/api/auth/me` | current user, or null |
| POST | `/api/auth/logout` | |
| GET | `/api/library` | the shelf: story summaries and the asset list |
| GET | `/api/library/export` | the whole shelf as a file |
| POST | `/api/library/import` | take in a file; always adds, never overwrites |
| GET | `/api/stories/{id}/export` | one story as a file |
| POST | `/api/stories` | create a story with its first chapter |
| GET | `/api/stories/{id}` | one story with every chapter and level |
| PATCH | `/api/stories/{id}` | title, cover, hot list, chapter order |
| DELETE | `/api/stories/{id}` | |
| POST | `/api/stories/{id}/chapters` | |
| PUT | `/api/stories/{id}/chapters/{id}/map` | nodes, edges, title, image — whole map |
| DELETE | `/api/stories/{id}/chapters/{id}` | |
| POST | `/api/stories/{id}/levels` | create into a chapter |
| PUT | `/api/stories/{id}/levels/{id}` | the editor save |
| DELETE | `/api/stories/{id}/chapters/{id}/levels/{id}` | unpin, delete if unused elsewhere |
| GET | `/api/content/levels/{hash}` | content-addressed, owner-scoped, cacheable forever |
| GET | `/api/progress` | finished level ids |
| POST | `/api/progress/complete` | |

The shelf returns summaries only — fifty stories should not ship every entity of
every level just to draw fifty covers. `GET /api/stories/{id}` carries an ETag
built from the fingerprint and the version.

Reads go through `LibraryReadModel` rather than the aggregate. Rebuilding a
`Story`, validating every value object, only to flatten it straight back into
JSON is work that buys nothing, and it would force the wire format to mirror the
model forever.

## Ids identify content only together with an owner

Every story lookup is scoped to the owner, and a story belonging to someone else
answers 404 rather than 403. That is not politeness about error codes: ids are
minted by the editor and unique per author, so two people genuinely can both
hold `story-first` — and they will, the moment either imports a shared file. A
global lookup would hand one author the other's content. 404 is also the smaller
leak, since 403 confirms the id exists.

`/api/content/levels/{hash}` was public in the first draft, reasoning that a
hash names one exact set of bytes and so cannot return the wrong version. True,
and beside the point: the fingerprint is thirty-two bits, walkable in an
afternoon, and every unpublished draft sits behind one. It needs a session and
returns only what the caller owns. It gets to be genuinely public once releases
exist — a released story is meant to be played by strangers, and that is the
thing worth caching forever.

## Import is the migration path

Everyone who has played so far has a library in localStorage, and an account
they cannot move it into is an account they will not use. So `POST
/api/library/import` came before releases and records.

The rule is the client's: always add, never overwrite. An id that is already
taken gets a new one and every reference inside the file is rewritten to match —
map nodes, path endpoints, chapter exits, hot lists, the chapter list on the
story. Miss one and the import succeeds while producing content that points at
the wrong thing, which is why the remapping table is an object rather than an
array in a handler. The new ids come back in the response, because the client is
still holding the file it sent and its ids may no longer be the ids the content
lives under.

A file written elsewhere makes no promises, and the aggregate refuses to hold
anything incoherent — correctly — so three decisions are made on the way in and
made out loud:

- a map node pointing at a level the file does not carry is dropped, with the
  paths touching it. Refusing the whole import over one missing level turns a
  partial file into no file;
- an exit leading to a chapter outside the file is cleared. Keeping it would aim
  it at whatever chapter happens to own that id in this account — worse than no
  exit, because the map would show a road onward into a stranger's story;
- a chapter with no story gets a shelter story, since a chapter belonging to
  nothing cannot be reached.

Each produces a warning rather than an error. A file missing one level is still
worth importing, and saying nothing would leave the author wondering where it
went.

The load-bearing test imports the actual `library.json` the game ships with and
checks that all six level fingerprints match the vectors the client produced. If
that ever fails, the migration path is gone.

## What progress does not do

It stores facts — this player finished this level — and nothing derived. Whether
a level is *unlocked* is a question about a chapter graph plus those facts, and
the graph belongs to the author, who may redraw a path at any moment. A stored
`unlocked` flag is a cached answer whose inputs change behind its back, and the
bug it produces is the worst kind: a player who can suddenly no longer reach a
level they had already opened. The rules stay the pure functions they already are
in `library.js`.

Completion is also not verified. In this iteration progress only decides what a
player sees on their own map, and cheating it spoils nobody else's game. Records
are a different matter, and they will not be taken on trust — see below.

## Known trade-offs

`composer stan` runs at level 6, not 8. Repositories read through the query
builder, which returns `stdClass`, so every row property access is an undefined
property at level 8. The answer is typed row objects per table, not a baseline
file — a baseline hides new mistakes among the accepted ones. The domain and
application layers do pass level 8 today and are checked separately by
`composer stan:domain`, so a regression there fails the build.

## Next

**Releases.** `publish()` freezes a story: content copied whole, a version
number, its own fingerprint. Immutable rows, and records attach to a release
rather than to a draft. The domain model for it is already implied by
`ContentHash` and the Merkle tree above.

**Runs, and where Node comes in.** A run is a log of input, not frames, so
crediting a record means re-simulating it. `src/core` is pure JavaScript, runs
headless in Node today, and is the only thing that can produce a bit-identical
result — a PHP reimplementation of the solver would diverge in one particle and
therefore in the outcome. So the verifier is a small Node worker behind a queue,
and PHP owns the decision, the storage and the leaderboards. In DDD terms it is
one port with one adapter, `RunVerifier`, and nothing else in the codebase learns
that a second language exists.

That is also why `RULES_VERSION` has to be pinned server-side: the physics can
change under a stored run, and a record from a different solver is not a record
on this one.
