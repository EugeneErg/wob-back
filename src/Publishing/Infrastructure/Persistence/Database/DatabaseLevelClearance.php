<?php

declare(strict_types=1);

namespace Wob\Publishing\Infrastructure\Persistence\Database;

use Illuminate\Database\ConnectionInterface;
use Wob\Publishing\Domain\Service\LevelClearance;
use Wob\Publishing\Domain\ValueObject\ReleaseId;

/**
 * Whether a player finished a level, in this release.
 *
 * Two kinds of evidence, and both are needed. A submitted run is the obvious
 * one — but most people finishing a level are not speedrunning it, and the
 * first version of this looked only at runs. The effect was that ordinary
 * players, the overwhelming majority, could never vote on anything: the right
 * to rate a level was accidentally reserved for people racing it.
 *
 * So a completion recorded in a save slot counts too, provided the slot is
 * playing this release. That proviso is the whole point of checking against a
 * release rather than a level: finishing something in version 2 says nothing
 * about version 5, where the same level may be a different puzzle.
 */
final readonly class DatabaseLevelClearance implements LevelClearance
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function hasCleared(ReleaseId $releaseId, string $levelPublicId, string $playerId): bool
    {
        $ranIt = $this->db->table('speedrun_records')
            ->where('release_id', $releaseId->value)
            ->where('runner_id', $playerId)
            ->where('scope', 'level')
            ->where('target_public_id', $levelPublicId)
            ->exists();

        if ($ranIt) {
            return true;
        }

        return $this->db->table('level_completions')
            ->join('save_slots', 'save_slots.id', '=', 'level_completions.slot_id')
            ->where('level_completions.user_id', $playerId)
            ->where('level_completions.level_public_id', $levelPublicId)
            ->where('save_slots.release_id', $releaseId->value)
            ->exists();
    }
}
