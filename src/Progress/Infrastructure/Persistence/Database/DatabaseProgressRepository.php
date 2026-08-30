<?php

declare(strict_types=1);

namespace Wob\Progress\Infrastructure\Persistence\Database;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Ramsey\Uuid\Uuid;
use Wob\Progress\Domain\Model\LevelCompletion;
use Wob\Progress\Domain\Repository\ProgressRepository;

/**
 * Progress is keyed by the internal level UUID, not by the public id the editor
 * minted. Public ids are unique per author, so two people can each own a level
 * called "lvl-tower"; keying on that would merge their progress.
 */
final readonly class DatabaseProgressRepository implements ProgressRepository
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function find(string $userId, string $levelId): ?LevelCompletion
    {
        $row = $this->db->table("level_completions")
            ->where("user_id", $userId)
            ->where("level_id", $levelId)
            ->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function of(string $userId): array
    {
        return $this->db->table("level_completions")
            ->where("user_id", $userId)
            ->get()
            ->map($this->hydrate(...))
            ->all();
    }

    public function save(LevelCompletion $completion): void
    {
        $this->db->table("level_completions")->upsert(
            [[
                "id" => Uuid::uuid4()->toString(),
                "user_id" => $completion->userId,
                "level_id" => $completion->levelId,
                "first_completed_at" => $completion->firstCompletedAt,
                "last_completed_at" => $completion->lastCompletedAt(),
                "completions" => $completion->completions(),
            ]],
            ["user_id", "level_id"],
            ["last_completed_at", "completions"],
        );
    }

    /** @return list<string> */
    public function completedLevelIds(string $userId): array
    {
        return $this->db->table("level_completions")
            ->join("levels", "levels.id", "=", "level_completions.level_id")
            ->where("level_completions.user_id", $userId)
            ->pluck("levels.public_id")
            ->values()
            ->all();
    }

    private function hydrate(object $row): LevelCompletion
    {
        return new LevelCompletion(
            $row->user_id,
            $row->level_id,
            new DateTimeImmutable($row->first_completed_at),
            new DateTimeImmutable($row->last_completed_at),
            (int) $row->completions,
        );
    }
}
