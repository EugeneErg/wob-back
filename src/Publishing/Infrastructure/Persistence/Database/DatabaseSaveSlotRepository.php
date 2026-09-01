<?php

declare(strict_types=1);

namespace Wob\Publishing\Infrastructure\Persistence\Database;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Domain\Model\SaveSlot;
use Wob\Publishing\Domain\Repository\SaveSlotRepository;
use Wob\Publishing\Domain\ValueObject\ReleaseId;
use Wob\Shared\Domain\Exception\NotFound;

final readonly class DatabaseSaveSlotRepository implements SaveSlotRepository
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function forPlayer(string $playerId, StoryId $storyId): array
    {
        $storyUuid = $this->storyUuid($storyId);

        $rows = $this->db->table('save_slots')
            ->where('player_id', $playerId)
            ->where('story_id', $storyUuid)
            ->orderBy('number')
            ->get()
            ->all();

        return array_map(fn (object $r): SaveSlot => $this->hydrate($r, $storyId), $rows);
    }

    public function find(string $slotId, string $playerId): ?SaveSlot
    {
        $row = $this->db->table('save_slots')
            ->where('id', $slotId)
            ->where('player_id', $playerId)
            ->first();

        if ($row === null) {
            return null;
        }

        $publicId = $this->db->table('stories')->where('id', $row->story_id)->value('public_id');

        return $this->hydrate($row, new StoryId((string) $publicId));
    }

    public function save(SaveSlot $slot): void
    {
        $values = [
            'player_id' => $slot->playerId,
            'story_id' => $this->storyUuid($slot->storyId),
            'number' => $slot->number,
            'label' => $slot->label(),
            'release_id' => $slot->releaseId()?->value,
            'last_played_at' => $slot->lastPlayedAt(),
            'updated_at' => now(),
        ];

        $this->db->table('save_slots')->upsert(
            [['id' => $slot->id, ...$values, 'created_at' => now()]],
            ['id'],
            ['label', 'release_id', 'last_played_at', 'updated_at'],
        );
    }

    public function clearProgress(string $slotId): void
    {
        $this->db->table('level_completions')->where('slot_id', $slotId)->delete();
    }

    public function remove(string $slotId): void
    {
        // The completions go with it by foreign key cascade.
        $this->db->table('save_slots')->where('id', $slotId)->delete();
    }

    /** @return list<string> */
    public function completedLevelIds(string $slotId): array
    {
        return $this->db->table('level_completions')
            ->where('slot_id', $slotId)
            ->pluck('level_public_id')
            ->values()
            ->all();
    }

    private function storyUuid(StoryId $storyId): string
    {
        $uuid = $this->db->table('stories')->where('public_id', $storyId->value)->value('id');

        return $uuid === null ? throw NotFound::of('Story', $storyId->value) : (string) $uuid;
    }

    private function hydrate(object $row, StoryId $storyId): SaveSlot
    {
        return SaveSlot::reconstitute(
            $row->id,
            $row->player_id,
            $storyId,
            (int) $row->number,
            $row->label,
            $row->release_id === null ? null : new ReleaseId($row->release_id),
            $row->last_played_at === null ? null : new DateTimeImmutable($row->last_played_at),
        );
    }
}
