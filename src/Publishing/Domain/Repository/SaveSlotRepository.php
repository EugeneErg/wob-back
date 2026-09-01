<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Repository;

use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Domain\Model\SaveSlot;

interface SaveSlotRepository
{
    /** @return list<SaveSlot> */
    public function forPlayer(string $playerId, StoryId $storyId): array;

    public function find(string $slotId, string $playerId): ?SaveSlot;

    public function save(SaveSlot $slot): void;

    /**
     * Wipe a run without deleting the slot: the player is starting over in the
     * same place on the shelf, which is what "erase" means in a save menu.
     */
    public function clearProgress(string $slotId): void;

    public function remove(string $slotId): void;

    /**
     * Which levels have been finished in this particular run.
     *
     * @return list<string>
     */
    public function completedLevelIds(string $slotId): array;
}
