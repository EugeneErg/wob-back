<?php

declare(strict_types=1);

namespace Wob\Publishing\Infrastructure\Persistence\Database;

use Illuminate\Database\ConnectionInterface;
use Ramsey\Uuid\Uuid;
use Wob\Publishing\Domain\Repository\ReleaseCompletionRepository;
use Wob\Publishing\Domain\ValueObject\ReleaseId;
use Wob\Publishing\Domain\ValueObject\RouteCompletion;

final readonly class DatabaseReleaseCompletionRepository implements ReleaseCompletionRepository
{
    /**
     * The 90% bar, expressed once in SQL. It mirrors
     * RouteCompletion::countsTowardsQuorum(), and the duplication is deliberate
     * rather than accidental: counting a hundred thousand rows in PHP to answer
     * "how many" would be absurd, so the rule exists in two places and the
     * feature test checks that the two agree.
     */
    private const QUORUM_FRACTION = 0.9;

    public function __construct(private ConnectionInterface $db)
    {
    }

    public function record(ReleaseId $releaseId, string $playerId, RouteCompletion $completion): void
    {
        $this->db->table('release_completions')->upsert(
            [[
                'id' => Uuid::uuid4()->toString(),
                'release_id' => $releaseId->value,
                'player_id' => $playerId,
                'levels_finished' => $completion->levelsFinished,
                'levels_on_route' => $completion->levelsOnRoute,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['release_id', 'player_id'],
            ['levels_finished', 'levels_on_route', 'updated_at'],
        );
    }

    public function forPlayer(ReleaseId $releaseId, string $playerId): ?RouteCompletion
    {
        $row = $this->db->table('release_completions')
            ->where('release_id', $releaseId->value)
            ->where('player_id', $playerId)
            ->first();

        return $row === null
            ? null
            : new RouteCompletion((int) $row->levels_finished, (int) $row->levels_on_route);
    }

    public function countAtQuorumThreshold(ReleaseId $releaseId): int
    {
        return $this->db->table('release_completions')
            ->where('release_id', $releaseId->value)
            ->where('levels_on_route', '>', 0)
            ->whereRaw('levels_finished::numeric / levels_on_route >= ?', [self::QUORUM_FRACTION])
            ->count();
    }
}
