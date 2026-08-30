<?php

declare(strict_types=1);

namespace Wob\Progress\Domain\Model;

use DateTimeImmutable;

/**
 * "This player finished this level." A fact, and only a fact.
 *
 * What is UNLOCKED is not stored, and that is the whole design of this context.
 * Unlocking is a question about a chapter graph plus a set of facts like this
 * one — and the graph belongs to the author, who may redraw a path at any
 * moment. A stored "unlocked" flag would be a cached answer to a question whose
 * inputs change behind its back, and the bug it produces is the worst kind:
 * a player who can suddenly no longer reach a level they had already opened.
 */
final class LevelCompletion
{
    public function __construct(
        public readonly string $userId,
        public readonly string $levelId,
        public readonly DateTimeImmutable $firstCompletedAt,
        private DateTimeImmutable $lastCompletedAt,
        private int $completions,
    ) {
    }

    public static function first(string $userId, string $levelId, DateTimeImmutable $at): self
    {
        return new self($userId, $levelId, $at, $at, 1);
    }

    public function again(DateTimeImmutable $at): void
    {
        $this->lastCompletedAt = $at;
        ++$this->completions;
    }

    public function lastCompletedAt(): DateTimeImmutable
    {
        return $this->lastCompletedAt;
    }

    public function completions(): int
    {
        return $this->completions;
    }
}
