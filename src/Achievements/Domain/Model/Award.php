<?php

declare(strict_types=1);

namespace Wob\Achievements\Domain\Model;

use DateTimeImmutable;

/**
 * Somebody having earned something.
 *
 * Carries its own points rather than looking them up. Rebalancing an
 * achievement later must not silently rewrite what people earned before the
 * change: a total that moves for reasons no player can see is worse than one
 * that is slightly behind the current values.
 */
final readonly class Award
{
    public function __construct(
        public string $userId,
        public string $code,
        public ?string $subjectType,
        public ?string $subjectId,
        public int $points,
        public DateTimeImmutable $awardedAt,
    ) {
    }
}
