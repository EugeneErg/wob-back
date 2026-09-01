<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Model;

use DateTimeImmutable;
use Wob\Publishing\Domain\ValueObject\ReleaseId;
use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * A recorded run against a frozen release.
 *
 * Times are in ticks, not seconds, because the simulation is fixed-rate: the
 * same playthrough gives the same number on a phone and on a workstation. A
 * stopwatch would make the leaderboard a measure of hardware.
 *
 * The run stores what the player did — the seed and the input log — rather than
 * the number they claim to have achieved. That is what makes verification
 * possible at all: the same input through the same physics produces the same
 * outcome, so the server can check the time instead of believing it. Nothing
 * verifies them yet; verifiedAt stays null until the Node worker that replays
 * them exists, and until then a record is a claim rather than a fact. That is
 * exactly why the field is here and nullable rather than absent — an
 * unverified record must be distinguishable from a checked one, and adding the
 * distinction later would leave every existing row lying about its status.
 */
final class SpeedrunRecord
{
    public const SCOPE_LEVEL = 'level';
    public const SCOPE_CHAPTER = 'chapter';
    public const SCOPE_STORY = 'story';

    public const ANY_PERCENT = 'any';
    public const HUNDRED_PERCENT = 'hundred';

    /** @param list<int> $input */
    public function __construct(
        public readonly string $id,
        public readonly ReleaseId $releaseId,
        public readonly string $runnerId,
        public readonly string $scope,
        public readonly ?string $targetPublicId,
        public readonly string $category,
        public readonly int $ticks,
        public readonly array $input,
        public readonly int $seed,
        public readonly string $rulesVersion,
        public readonly DateTimeImmutable $setAt,
        private ?DateTimeImmutable $verifiedAt = null,
    ) {
        if (!in_array($scope, [self::SCOPE_LEVEL, self::SCOPE_CHAPTER, self::SCOPE_STORY], true)) {
            throw InvariantViolation::because(sprintf('"%s" is not something a run can be against', $scope));
        }

        if (!in_array($category, [self::ANY_PERCENT, self::HUNDRED_PERCENT], true)) {
            throw InvariantViolation::because(sprintf('"%s" is not a category', $category));
        }

        // A story run covers the whole story and names no target; the other two
        // must say what they were against, or the leaderboard has no column to
        // put them in.
        if ($scope !== self::SCOPE_STORY && ($targetPublicId === null || $targetPublicId === '')) {
            throw InvariantViolation::because(sprintf('A %s run must say which %s', $scope, $scope));
        }

        if ($ticks < 1) {
            throw InvariantViolation::because('A run that took no time did not happen');
        }
    }

    public function isVerified(): bool
    {
        return $this->verifiedAt !== null;
    }

    public function verifiedAt(): ?DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function verified(DateTimeImmutable $at): void
    {
        $this->verifiedAt ??= $at;
    }
}
