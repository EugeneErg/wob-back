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

    public function find(string $userId, string $levelId, ?string $slotId = null): ?LevelCompletion
    {
        $row = $this->db->table("level_completions")
            ->where("user_id", $userId)
            ->where("level_public_id", $levelId)
            ->when(
                $slotId === null,
                static fn ($q) => $q->whereNull("slot_id"),
                static fn ($q) => $q->where("slot_id", $slotId),
            )
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

    /**
     * Written by hand rather than through upsert(), because the uniqueness this
     * has to respect lives in two partial indexes — one for progress inside a
     * run, one for the runless progress that predates slots — and ON CONFLICT
     * can only name one of them. Postgres will not infer a partial index
     * without its predicate, so the predicate is spelled out.
     */
    public function save(LevelCompletion $completion): void
    {
        $existing = $this->db->table("level_completions")
            ->where("user_id", $completion->userId)
            ->where("level_public_id", $completion->levelId)
            ->when(
                $completion->slotId === null,
                static fn ($q) => $q->whereNull("slot_id"),
                static fn ($q) => $q->where("slot_id", $completion->slotId),
            )
            ->first();

        if ($existing !== null) {
            $this->db->table("level_completions")->where("id", $existing->id)->update([
                "last_completed_at" => $completion->lastCompletedAt(),
                "completions" => $completion->completions(),
            ]);

            return;
        }

        $this->db->table("level_completions")->insert([
            "id" => Uuid::uuid4()->toString(),
            "user_id" => $completion->userId,
            "slot_id" => $completion->slotId,
            "level_public_id" => $completion->levelId,
            "first_completed_at" => $completion->firstCompletedAt,
            "last_completed_at" => $completion->lastCompletedAt(),
            "completions" => $completion->completions(),
        ]);
    }

    /** @return list<string> */
    public function completedLevelIds(string $userId): array
    {
        return $this->db->table("level_completions")
            ->where("user_id", $userId)
            ->pluck("level_public_id")
            ->values()
            ->all();
    }

    private function hydrate(object $row): LevelCompletion
    {
        return new LevelCompletion(
            $row->user_id,
            $row->level_public_id,
            $row->slot_id,
            new DateTimeImmutable($row->first_completed_at),
            new DateTimeImmutable($row->last_completed_at),
            (int) $row->completions,
        );
    }
}
