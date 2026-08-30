<?php

declare(strict_types=1);

namespace Wob\Progress\Application\Handler;

use Wob\Progress\Application\Command\CompleteLevel;
use Wob\Progress\Domain\Model\LevelCompletion;
use Wob\Progress\Domain\Repository\ProgressRepository;
use Wob\Shared\Domain\Clock;

/**
 * Idempotent by design. The client marks a level done at the moment the last
 * ball reaches the pipe, and that message can arrive twice — a flaky connection,
 * a retry, a second tab. Finishing a level twice is a real thing that happens,
 * so it is counted rather than rejected, and the first time is kept.
 *
 * Note what this handler does NOT do: it does not verify that the level was
 * actually finished. In iteration one, progress is a convenience — it decides
 * what the player sees unlocked on their own map, and cheating it only spoils
 * their own game. Records are a different matter entirely, and when they arrive
 * they will not be taken on trust: the server will re-run the recorded input
 * through the same physics and check the outcome itself.
 */
final readonly class CompleteLevelHandler
{
    public function __construct(private ProgressRepository $progress, private Clock $clock)
    {
    }

    public function __invoke(CompleteLevel $command): LevelCompletion
    {
        $existing = $this->progress->find($command->userId, $command->levelId);

        if ($existing !== null) {
            $existing->again($this->clock->now());
            $this->progress->save($existing);

            return $existing;
        }

        $completion = LevelCompletion::first($command->userId, $command->levelId, $this->clock->now());
        $this->progress->save($completion);

        return $completion;
    }
}
