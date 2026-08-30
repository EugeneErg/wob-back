<?php

declare(strict_types=1);

namespace Wob\Progress\Domain\Repository;

use Wob\Progress\Domain\Model\LevelCompletion;

interface ProgressRepository
{
    public function find(string $userId, string $levelId): ?LevelCompletion;

    /** @return list<LevelCompletion> */
    public function of(string $userId): array;

    public function save(LevelCompletion $completion): void;

    /**
     * Level ids the user has finished, as a flat list — what the client asks for.
     *
     * @return list<string>
     */
    public function completedLevelIds(string $userId): array;
}
