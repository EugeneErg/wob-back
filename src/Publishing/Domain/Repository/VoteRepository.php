<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Repository;

use Wob\Publishing\Domain\Model\Vote;
use Wob\Publishing\Domain\ValueObject\ReleaseId;

interface VoteRepository
{
    /** @return list<Vote> */
    public function forLevel(ReleaseId $releaseId, string $levelPublicId): array;

    /** @param list<Vote> $votes */
    public function saveAll(array $votes): void;

    public function save(Vote $vote): void;

    public function findOne(ReleaseId $releaseId, string $levelPublicId, string $voterId): ?Vote;

    /**
     * The mean of every rating on this release, across all its levels.
     *
     * Computed in the database rather than by loading votes into PHP: a
     * popular release has tens of thousands of them, and the only thing the
     * canon check needs is one number.
     */
    public function averageRating(ReleaseId $releaseId): float;

    public function countFor(ReleaseId $releaseId): int;
}
