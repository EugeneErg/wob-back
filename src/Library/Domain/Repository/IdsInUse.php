<?php

declare(strict_types=1);

namespace Wob\Library\Domain\Repository;

/**
 * The set of client-minted ids already taken, so an import can rename a
 * newcomer instead of overwriting a stranger. A dedicated type rather than three
 * bare arrays: passing string[] around invites mixing up which list is which,
 * and the mistake would be silent — the wrong story quietly replaced.
 */
final readonly class IdsInUse
{
    /**
     * @param array<string, true> $stories
     * @param array<string, true> $chapters
     * @param array<string, true> $levels
     * @param array<string, true> $assets
     */
    public function __construct(
        private array $stories = [],
        private array $chapters = [],
        private array $levels = [],
        private array $assets = [],
    ) {
    }

    public function hasStory(string $id): bool
    {
        return isset($this->stories[$id]);
    }

    public function hasChapter(string $id): bool
    {
        return isset($this->chapters[$id]);
    }

    public function hasLevel(string $id): bool
    {
        return isset($this->levels[$id]);
    }

    public function hasAsset(string $id): bool
    {
        return isset($this->assets[$id]);
    }
}
