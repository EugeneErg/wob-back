<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Repository;

use Wob\Publishing\Domain\ValueObject\ReleaseId;
use Wob\Publishing\Domain\ValueObject\RouteCompletion;

/**
 * How far each player got through a release — the evidence the canon quorum is
 * counted from, and the evidence that decides who is allowed to vote.
 */
interface ReleaseCompletionRepository
{
    public function record(ReleaseId $releaseId, string $playerId, RouteCompletion $completion): void;

    public function forPlayer(ReleaseId $releaseId, string $playerId): ?RouteCompletion;

    /** How many distinct players cleared 90% of their own route. */
    public function countAtQuorumThreshold(ReleaseId $releaseId): int;
}
