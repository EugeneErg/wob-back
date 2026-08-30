<?php

declare(strict_types=1);

namespace Wob\Library\Application\Import;

use Wob\Library\Domain\Repository\IdsInUse;
use Wob\Library\Domain\Service\IdGenerator;

/**
 * Decides what each id in an incoming bundle becomes, and remembers it.
 *
 * Import never overwrites. That is the rule the client already follows and the
 * only one that makes sense for a shared file: if a story you are importing
 * happens to carry an id you already use, the newcomer is renamed and every
 * reference to it inside the file is rewritten. Silently replacing what is
 * already there would destroy work on an id collision, and id collisions are
 * expected — the file may well be a copy of a story you already have.
 *
 * The remapping table exists as an object rather than an array in a handler
 * because every reference in the bundle has to go through it: map nodes, path
 * endpoints, chapter exits, hot asset lists, and the chapter list on the story.
 * Miss one and the import succeeds while producing content that points at the
 * wrong thing.
 */
final class IdMap
{
    /** @var array<string, string> old id => id it will be stored under */
    private array $map = [];

    public function __construct(
        private readonly IdsInUse $taken,
        private readonly IdGenerator $ids,
    ) {
    }

    /**
     * Reserve an id for something in the bundle, renaming if it is taken.
     *
     * Reservation is deliberately separate from use: all chapter ids are
     * reserved before any chapter is built, because a chapter exit may point at
     * a chapter that appears later in the file. Resolving as we go would look up
     * an id that does not exist yet and leave the reference pointing at the old
     * one — into whatever story happens to own it here.
     */
    public function reserve(string $prefix, string $id, bool $isTaken): string
    {
        if (isset($this->map[$id])) {
            return $this->map[$id];
        }

        return $this->map[$id] = $isTaken ? $this->ids->next($prefix) : $id;
    }

    public function reserveStory(string $id): string
    {
        return $this->reserve('story', $id, $this->taken->hasStory($id));
    }

    public function reserveChapter(string $id): string
    {
        return $this->reserve('ch', $id, $this->taken->hasChapter($id));
    }

    public function reserveLevel(string $id): string
    {
        return $this->reserve('lvl', $id, $this->taken->hasLevel($id));
    }

    public function reserveAsset(string $id): string
    {
        return $this->reserve('as', $id, $this->taken->hasAsset($id));
    }

    /** An asset that already exists here under a different id: point at the existing one. */
    public function pointAt(string $oldId, string $existingId): string
    {
        return $this->map[$oldId] = $existingId;
    }

    public function has(string $id): bool
    {
        return isset($this->map[$id]);
    }

    /**
     * An id that was never reserved is a reference out of the bundle, and it is
     * returned unchanged so the caller can notice and decide. Silently mapping
     * it to something is how a dangling reference becomes a wrong one.
     */
    public function resolve(string $id): string
    {
        return $this->map[$id] ?? $id;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->map;
    }
}
