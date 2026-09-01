// Regenerates tests/Fixtures/content-hashes.json from the real client code.
//
// The point is that these vectors come from the game itself, not from a second
// implementation of the same idea written from the same misunderstanding. Point
// it at a checkout of the game and pipe the output into the fixture:
//
//   node tools/hashes.mjs ../wob > tests/Fixtures/content-hashes.json
//
// Run it again whenever core/releases.js changes.

import { readFileSync } from "node:fs";
import { join } from "node:path";

const root = process.argv[2] ?? "../wob";
const lib = JSON.parse(readFileSync(join(root, "src/levels/library.json"), "utf8"));

const fnv = (str) => {
  let h = 2166136261;
  for (let i = 0; i < str.length; i++) {
    h ^= str.charCodeAt(i);
    h = Math.imul(h, 16777619);
  }
  return (h >>> 0).toString(16).padStart(8, "0");
};

const stable = (v) => {
  if (v === null || typeof v !== "object") return JSON.stringify(v);
  if (Array.isArray(v)) return `[${v.map(stable).join(",")}]`;
  return `{${Object.keys(v).sort().map((k) => `${JSON.stringify(k)}:${stable(v[k])}`).join(",")}}`;
};

const level = (id) => lib.levels.find((l) => l.id === id);
const chapter = (id) => lib.chapters.find((c) => c.id === id);

const levelHash = (l) => {
  if (!l) return null;
  const { id, width, height, gravity, goal, entities } = l;
  return fnv(stable({ id, width, height, gravity, goal, entities }));
};

// Level names count towards the CHAPTER, and chapter titles towards the STORY:
// a name belongs one level above the thing it names, so renaming never
// invalidates the records set on the thing itself.
const chapterHash = (ch) => {
  if (!ch) return null;
  const levels = ch.nodes
    .map((n) => `${n.levelId}:${levelHash(level(n.levelId))}:${level(n.levelId)?.name ?? ""}`)
    .sort();
  const edges = ch.edges.map((e) => `${e.from}>${e.to}`).sort();
  return fnv(stable({ id: ch.id, levels, edges }));
};

const storyHash = (s) => {
  if (!s) return null;
  const chapters = s.chapters.map((c) => `${c}:${chapterHash(chapter(c))}:${chapter(c)?.title ?? ""}`);
  return fnv(stable({ id: s.id, title: s.title, chapters }));
};

const out = { scalars: {}, levels: {}, chapters: {}, stories: {} };

// Scalar vectors pin down the formatting rules that are easy to get wrong in
// another language: key order, whole floats, slashes, non-ASCII, exponents.
out.scalars["sorted-keys"] = fnv(stable({ b: [1, 2], a: 1 }));
out.scalars["empty-object"] = fnv(stable({}));
out.scalars["unicode"] = fnv(stable({ t: 'Лунка / "x"' }));
out.scalars["floats"] = fnv(stable({ x: 0.1, y: -1800.5, z: 1e21 }));

for (const l of lib.levels) out.levels[l.id] = levelHash(l);
for (const c of lib.chapters) out.chapters[c.id] = chapterHash(c);
for (const s of lib.stories) out.stories[s.id] = storyHash(s);

console.log(JSON.stringify(out, null, 2));
