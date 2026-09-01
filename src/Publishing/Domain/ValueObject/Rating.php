<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\ValueObject;

use Wob\Shared\Domain\Exception\InvariantViolation;

/** A single player's vote on a single level of a single release: 1 to 10. */
final readonly class Rating
{
    private const MIN = 1;
    private const MAX = 10;

    public function __construct(public int $value)
    {
        if ($value < self::MIN || $value > self::MAX) {
            throw InvariantViolation::because(
                sprintf('A rating must be between %d and %d', self::MIN, self::MAX),
            );
        }
    }
}
