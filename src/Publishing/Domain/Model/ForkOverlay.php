<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Model;

use stdClass;
use Wob\Publishing\Domain\ValueObject\ContentSnapshot;
use Wob\Shared\Domain\Exception\NotFound;

/**
 * A fork's content: its own overrides, with everything else read straight from
 * the release it came from.
 *
 * This is the copy-on-write made readable. A fork stores only what its editor
 * actually touched — edit a level's entities and the fork holds that one level;
 * rearrange a chapter's map and it holds that one chapter. Everything
 * untouched has no row anywhere, and is resolved from the base release on
 * demand.
 *
 * Ids do not change on copy. A public id names a level, not a row, so a fork's
 * version of `lvl-tower` is still `lvl-tower` — which is exactly what keeps a
 * chapter that was NOT copied still valid: its nodes point at the same ids, and
 * each one resolves to whichever version exists, the fork's or the base's. That
 * is why editing a level's contents does not drag its chapter into the fork,
 * and it is the whole reason this stays cheap.
 *
 * Deletion cannot be expressed by absence — absence already means "not
 * touched, go look at the base" — so it needs a tombstone. Without one,
 * deleting a level in a fork would silently resurrect it from the base on the
 * next read.
 */
final readonly class ForkOverlay
{
    /**
     * @param array<string, stdClass> $levelOverrides   keyed by level public id
     * @param array<string, stdClass> $chapterOverrides keyed by chapter public id
     * @param list<string>            $deletedLevels
     * @param list<string>            $deletedChapters
     */
    public function __construct(
        private ContentSnapshot $base,
        private array $levelOverrides = [],
        private array $chapterOverrides = [],
        private array $deletedLevels = [],
        private array $deletedChapters = [],
    ) {
    }

    public function level(string $id): stdClass
    {
        if (in_array($id, $this->deletedLevels, true)) {
            throw NotFound::of('Level', $id);
        }

        return $this->levelOverrides[$id]
            ?? $this->base->level($id)
            ?? throw NotFound::of('Level', $id);
    }

    public function chapter(string $id): stdClass
    {
        if (in_array($id, $this->deletedChapters, true)) {
            throw NotFound::of('Chapter', $id);
        }

        return $this->chapterOverrides[$id]
            ?? $this->base->chapter($id)
            ?? throw NotFound::of('Chapter', $id);
    }

    /**
     * The fork's full content, flattened — what a release cut from this fork
     * would freeze, and what the player is actually playing.
     */
    public function flatten(): ContentSnapshot
    {
        $chapters = [];

        foreach ($this->base->chapters as $chapter) {
            if (in_array($chapter->id, $this->deletedChapters, true)) {
                continue;
            }

            $chapters[$chapter->id] = $this->chapterOverrides[$chapter->id] ?? $chapter;
        }

        // Chapters the fork added outright, which the base has never heard of.
        foreach ($this->chapterOverrides as $id => $chapter) {
            $chapters[$id] ??= $chapter;
        }

        $levels = [];

        foreach ($this->base->levels as $level) {
            if (in_array($level->id, $this->deletedLevels, true)) {
                continue;
            }

            $levels[$level->id] = $this->levelOverrides[$level->id] ?? $level;
        }

        foreach ($this->levelOverrides as $id => $level) {
            $levels[$id] ??= $level;
        }

        return new ContentSnapshot(array_values($chapters), array_values($levels));
    }

    /**
     * Which levels this fork actually changed — what a pull request would carry.
     *
     * @return list<string>
     */
    public function changedLevelIds(): array
    {
        return array_values(array_map(strval(...), array_keys($this->levelOverrides)));
    }

    /** @return list<string> */
    public function changedChapterIds(): array
    {
        return array_values(array_map(strval(...), array_keys($this->chapterOverrides)));
    }

    public function isUntouched(): bool
    {
        return $this->levelOverrides === []
            && $this->chapterOverrides === []
            && $this->deletedLevels === []
            && $this->deletedChapters === [];
    }
}
