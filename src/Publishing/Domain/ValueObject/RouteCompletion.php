<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\ValueObject;

use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * How much of a player's OWN route through a release they finished.
 *
 * "90% of the story" is ambiguous the moment a story branches: 90% of every
 * level the story could possibly contain, or 90% of the levels on the one path
 * this particular player actually walked? The two disagree sharply on a
 * two-branch story — someone who finished one branch in full has covered half
 * the story's total levels but all of their own route.
 *
 * This is the second reading, deliberately: it is the same notion of "route"
 * that already decides any% and 100% in core/chain.js, just relaxed from "every
 * level on the route" to "most of them". A quorum built on the first reading
 * would punish branching stories for existing.
 */
final readonly class RouteCompletion
{
    private const CANON_THRESHOLD = 0.9;

    public function __construct(
        public int $levelsFinished,
        public int $levelsOnRoute,
    ) {
        if ($levelsOnRoute < 0 || $levelsFinished < 0 || $levelsFinished > $levelsOnRoute) {
            throw InvariantViolation::because('Levels finished cannot exceed levels on the route');
        }
    }

    public function fraction(): float
    {
        return $this->levelsOnRoute === 0 ? 0.0 : $this->levelsFinished / $this->levelsOnRoute;
    }

    /** Whether this player counts towards a release's canon quorum. */
    public function countsTowardsQuorum(): bool
    {
        return $this->fraction() >= self::CANON_THRESHOLD;
    }
}
