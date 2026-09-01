<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\ValueObject;

use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * How much a level changed between two releases, as a fraction: 0.0 is
 * identical content, 1.0 is completely different.
 *
 * A first-class type rather than a bare float, because it sits at the pivot of
 * a decision that will be second-guessed the moment it looks wrong: how many of
 * the old votes survive a new release. "0.85" printed in a log is a number;
 * carryOverFraction() is the rule that number is subject to.
 */
final readonly class Similarity
{
    public function __construct(public float $value)
    {
        if ($value < 0.0 || $value > 1.0) {
            throw InvariantViolation::because('Similarity must be between 0.0 and 1.0');
        }
    }

    public static function identical(): self
    {
        return new self(0.0);
    }

    public static function unrelated(): self
    {
        return new self(1.0);
    }

    /**
     * The fraction of old votes that survive a change of this size.
     *
     * A level edited beyond recognition should carry over none of its old
     * opinions; a level nudged by a pixel should carry over almost all of them.
     * The mapping is the identity — "1% different content, 1% of votes
     * discarded" — because any curve steeper or shallower than that would be a
     * claim about how much a given edit matters that nobody asked the design to
     * make. Linear is the assumption that asks for the least trust.
     */
    public function carryOverFraction(): float
    {
        return 1.0 - $this->value;
    }
}
