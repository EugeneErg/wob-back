<?php

declare(strict_types=1);

namespace Wob\Achievements\Domain\Repository;

use Wob\Achievements\Domain\Model\Award;

interface AwardRepository
{
    /**
     * Record an award, unless this person already has it for this subject.
     *
     * Idempotent because every caller is a trigger that fires more than once —
     * finishing a level, re-checking a board — and none of them should have to
     * remember what has already been granted.
     *
     * @return bool whether it was new
     */
    public function grant(Award $award): bool;

    public function has(string $userId, string $code, ?string $subjectId = null): bool;

    /** @return list<Award> */
    public function forUser(string $userId): array;

    public function totalPoints(string $userId): int;

    /**
     * Standing by points, best first — the one board that spans everything a
     * person does rather than one story or one level.
     *
     * @return list<array<string, mixed>>
     */
    public function ranking(int $limit = 50): array;
}
