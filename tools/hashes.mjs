// Regenerates tests/Fixtures/content-hashes.json from the real client code.
//
// The point is that these vectors come from the game itself, not from a second
// implementation of the same idea written from the same misunderstanding. Point
// it at a checkout of the game and pipe the output into the fixture:
//
//   node tools/hashes.mjs ../wob > tests/Fixtures/content-hashes.json
//
// Run it again whenever core/releases.js changes.
//
// It used to say all of that while carrying its own copy of the formula, which
// made it exactly the second implementation it warns about — and the copy went
// stale, so the PHP parity test ended up comparing the domain against a third
// opinion. It now imports core/releases.js and asks the game.
//
// That takes two props. The library reads from localStorage, which does not
// exist in node, so a map stands in for it; and the built-in content has to be
// seeded before any hash is asked for, because chapter and story fingerprints
// look up the levels they contain.

import { readFileSync } from "node:fs";
import { join, resolve } from "node:path";
import { pathToFileURL } from "node:url";

const store = new Map();

globalThis.localStorage = {
  get length() { return store.size; },
  key: (i) => [...store.keys()][i],
  getItem: (k) => (store.has(k) ? store.get(k) : null),
  setItem: (k, v) => store.set(k, String(v)),
  removeItem: (k) => store.delete(k),
  clear: () => store.clear(),
};

const root = resolve(process.argv[2] ?? "../wob");
const load = (rel) => import(pathToFileURL(join(root, rel)).href);

const library = await load("src/core/library.js");
const { fnv, stable, levelHash, chapterHash, storyHash } = await load("src/core/releases.js");

const builtin = JSON.parse(readFileSync(join(root, "src/levels/library.json"), "utf8"));
library.save(structuredClone(builtin));

const out = { scalars: {}, levels: {}, chapters: {}, stories: {} };

// Scalar vectors pin down the formatting rules that are easy to get wrong in
// another language: key order, whole floats, slashes, non-ASCII, exponents.
out.scalars["sorted-keys"] = fnv(stable({ b: [1, 2], a: 1 }));
out.scalars["empty-object"] = fnv(stable({}));
out.scalars["unicode"] = fnv(stable({ t: 'Лунка / "x"' }));
out.scalars["floats"] = fnv(stable({ x: 0.1, y: -1800.5, z: 1e21 }));

for (const l of builtin.levels) out.levels[l.id] = levelHash(l);
for (const c of builtin.chapters) out.chapters[c.id] = chapterHash(c);
for (const s of builtin.stories) out.stories[s.id] = storyHash(s);

console.log(JSON.stringify(out, null, 2));
