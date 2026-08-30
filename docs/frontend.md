# Wiring the game to this backend

Nothing in `src/core` needs restructuring. The seams are already there.

## Same-site in development

The session cookie only travels if the SPA and the API share an origin, so proxy
`/api` from Vite instead of pointing `fetch` at port 8000:

```js
// vite.config.js
export default defineConfig({
  server: {
    proxy: { '/api': { target: 'http://localhost:8000', changeOrigin: true } },
  },
})
```

Every request then goes to `/api/...` on `localhost:5173`, and needs
`credentials: 'include'`.

## Sign-in

Load Google Identity Services, and hand the credential it returns straight to the
backend. Nothing else about the token is the client's business.

```js
// src/core/session.js
export async function signIn(credential) {
  const r = await fetch('/api/auth/google', {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ credential }),
  })
  if (!r.ok) throw new Error('Sign-in failed')
  return (await r.json()).user
}

export const currentUser = () =>
  fetch('/api/auth/me', { credentials: 'include' }).then((r) => r.json()).then((d) => d.user)
```

## Content source

`core/content.js` ends with the exact call, in a comment. Fill it in:

```js
import { setContentSource } from './core/content.js'

setContentSource({
  async get({ hash }) {
    if (!hash) return null
    const r = await fetch(`/api/content/levels/${hash}`)
    return r.ok ? r.json() : null   // null means "that version is gone", and the
                                    // replay screen already knows what to do
  },
})
```

The lookup is by fingerprint, not by id, so it cannot return the wrong version:
a level edited since is simply a different hash.

## Moving an existing library into an account

The first thing a returning player needs. `exportAll()` already produces exactly
the file the server reads, so the migration is one call:

```js
import { exportAll } from './core/library.js'

const r = await fetch('/api/library/import', {
  method: 'POST',
  credentials: 'include',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify(exportAll()),
})

const { stories, idMap, warnings } = await r.json()
```

`idMap` matters: if any id collided with something already in the account, the
newcomer was renamed and every reference inside the file was rewritten. Local
progress is keyed on level ids, so run it through the map before uploading it,
or the player finishes the migration with an empty map.

`warnings` is the list of things that were dropped rather than refused — a node
pointing at a missing level, an exit leading out of the file. Show them; the
author will otherwise wonder where the level went.

## The library

`core/library.js` is synchronous and reads `localStorage` directly, which is the
one thing that does need work. Two ways to go, in order of effort:

**Cache-through.** Keep `library()` synchronous over the local copy, fetch
`/api/library` and `/api/stories/{id}` on sign-in to fill it, and push every
`save()` to the server in the background. The editor stays instant, offline
still works, and the version field catches the conflicts.

**Async library.** Turn the getters into promises, the way `replays.js` already
did on purpose — its comment says exactly why: writing it synchronously means
rewriting every call site later, whereas an async interface means only the
adapter changes.

The first is less work and keeps offline editing. The second is cleaner. Either
way, `save()` sends the version it loaded and handles `409` by reloading rather
than retrying blind.

## Progress

`isDone`, `levelOpen`, `chapterOpen`, `edgeVisible` stay exactly as they are:
they are pure functions of the chapter graph plus a set of finished level ids.
Only the source of that set changes.

```js
const done = new Set((await fetch('/api/progress', { credentials: 'include' }).then((r) => r.json())).completed)
export const isDone = (levelId) => done.has(levelId)

export function markDone(storyId, levelId) {
  done.add(levelId)                       // optimistic: the map redraws now
  return fetch('/api/progress/complete', {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ storyId, levelId }),
  })
}
```

## Settings stay local

`fpsCap` and `showFps` describe a device, not a player. Syncing them would push a
weak laptop's frame cap onto a desktop. They belong in `localStorage` and there
is no endpoint for them.
