<?php

declare(strict_types=1);

namespace Wob\Publishing\Application\Handler;

use Ramsey\Uuid\Uuid;
use Wob\Publishing\Application\Command\SubmitRun;
use Wob\Publishing\Domain\Model\SpeedrunRecord;
use Wob\Publishing\Domain\Repository\ReleaseRepository;
use Wob\Achievements\Application\Handler\GrantAwards;
use Wob\Publishing\Domain\Repository\SpeedrunRecordRepository;
use Wob\Publishing\Domain\ValueObject\ReleaseId;
use Wob\Shared\Domain\Clock;
use Wob\Shared\Domain\Exception\InvariantViolation;
use Wob\Shared\Domain\Exception\NotFound;

/**
 * Take in a finished run.
 *
 * What is checked here is that the run refers to something real: a release that
 * exists, and a level or chapter that release actually contains. What is NOT
 * checked is whether the time is honest — that needs replaying the input
 * through the same physics, which only the game's own solver can do, and it
 * runs in Node rather than here.
 *
 * So a record arrives unverified and says so. The check is deliberately not
 * faked in the meantime: a plausibility heuristic on the server would give the
 * appearance of verification while catching nothing a determined cheat could
 * not walk around, and the board would present its results as though they had
 * been confirmed.
 */
final readonly class SubmitRunHandler
{
    public function __construct(
        private ReleaseRepository $releases,
        private SpeedrunRecordRepository $records,
        private GrantAwards $awards,
        private Clock $clock,
    ) {
    }

    public function __invoke(SubmitRun $command): SpeedrunRecord
    {
        $releaseId = new ReleaseId($command->releaseId);
        $release = $this->releases->get($releaseId);

        if (!$release->isClearedByAuthor()) {
            throw InvariantViolation::because('This version is not open for runs yet');
        }

        $this->assertTargetExists($release, $command);

        $record = new SpeedrunRecord(
            Uuid::uuid4()->toString(),
            $releaseId,
            $command->runnerId,
            $command->scope,
            $command->targetId,
            $command->category,
            $command->ticks,
            $command->input,
            $command->seed,
            $command->rulesVersion,
            $this->clock->now(),
        );

        $this->records->save($record);

        $this->awards->afterRunSubmitted(
            $command->runnerId,
            $releaseId->value,
            $command->scope,
            $command->targetId,
            $command->category,
        );

        return $record;
    }

    private function assertTargetExists(object $release, SubmitRun $command): void
    {
        if ($command->scope === SpeedrunRecord::SCOPE_STORY) {
            return;
        }

        $found = $command->scope === SpeedrunRecord::SCOPE_LEVEL
            ? $release->content->level((string) $command->targetId)
            : $release->content->chapter((string) $command->targetId);

        if ($found === null) {
            throw NotFound::of(ucfirst($command->scope), (string) $command->targetId);
        }
    }
}
