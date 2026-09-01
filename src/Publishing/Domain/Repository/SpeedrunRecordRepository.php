<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Repository;

use Wob\Publishing\Domain\Model\SpeedrunRecord;
use Wob\Publishing\Domain\ValueObject\ReleaseId;

interface SpeedrunRecordRepository
{
    public function save(SpeedrunRecord $record): void;

    public function find(string $id): ?SpeedrunRecord;

    /**
     * The leaderboard: fastest first, one entry per runner.
     *
     * One entry per runner because a table where the same person holds the top
     * five places is not a ranking, it is a list of their attempts — and it
     * pushes everyone else off the page.
     *
     * @return list<array<string, mixed>>
     */
    public function leaderboard(
        ReleaseId $releaseId,
        string $scope,
        ?string $targetPublicId,
        string $category,
        int $limit = 50,
        ?string $rulesVersion = null,
        /**
         * Whose unverified times to include alongside the checked ones.
         *
         * Null shows only what has been verified. A runner sees their own
         * pending time because from their side the run happened, and leaving
         * it out would read as the game having lost it.
         */
        ?string $viewerId = null,
    ): array;

    /** Whether this player has any finished run of this level in this release. */
    public function hasRunOf(ReleaseId $releaseId, string $levelPublicId, string $runnerId): bool;

    /** A player's own best against one target, for showing next to the board. */
    public function personalBest(
        ReleaseId $releaseId,
        string $scope,
        ?string $targetPublicId,
        string $category,
        string $runnerId,
    ): ?SpeedrunRecord;
}
