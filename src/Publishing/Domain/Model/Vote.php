<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Model;

use DateTimeImmutable;
use Wob\Publishing\Domain\ValueObject\Rating;
use Wob\Publishing\Domain\ValueObject\ReleaseId;

/**
 * One player's opinion of one level of one release.
 *
 * The unit of opinion is deliberately the level, not the story. A story is
 * whatever its levels are, and grading it as a whole would let a single click
 * stand in for levels the voter never touched — including, worst case, ones
 * the author added after that voter last played. "Rate the whole story" in the
 * product is a convenience that fires one Vote per finished level with the
 * same score; it is not a different kind of vote.
 *
 * A vote belongs to a release, not to a story. When a new release changes a
 * level, the old vote does not silently start describing different content —
 * it carries forward at reduced weight instead.
 */
final readonly class Vote
{
    public function __construct(
        public ReleaseId $releaseId,
        public string $levelId,
        public string $voterId,
        public Rating $rating,
        public DateTimeImmutable $castAt,
        /**
         * True for a vote a player placed themselves; false for one that
         * arrived by carrying forward from an earlier release.
         *
         * Kept distinct so a carried-over vote can be told apart later — a
         * moderation tool, an audit, a future rule that only fresh votes count
         * towards something all need to ask this question, and a boolean that
         * is thrown away at write time cannot be recovered by a query.
         */
        public bool $carriedOver = false,
        /**
         * How much this opinion counts, from 0 to 1.
         *
         * Full weight means the voter played the content they are rating.
         * Less means their opinion was formed on an earlier version of the
         * level and has been carried across an edit — it still counts, in
         * proportion to how much of the level they actually saw.
         *
         * The alternative was discarding a share of the votes outright, and it
         * was worse for a reason that only appears from the voter's side: their
         * opinion either survived whole or vanished, decided by nothing they
         * could see. Weight lets everyone's opinion fade by the same amount,
         * and lets anyone restore theirs by playing the new version.
         */
        public float $weight = 1.0,
    ) {
    }

    public function withWeight(float $weight): self
    {
        return new self(
            $this->releaseId,
            $this->levelId,
            $this->voterId,
            $this->rating,
            $this->castAt,
            $this->carriedOver,
            max(0.0, min(1.0, $weight)),
        );
    }
}
