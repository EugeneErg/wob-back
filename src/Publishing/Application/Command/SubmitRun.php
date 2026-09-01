<?php

declare(strict_types=1);

namespace Wob\Publishing\Application\Command;

/**
 * A finished run, offered to the leaderboard.
 *
 * Carries the input log and the seed rather than only the time, because those
 * are what make the time checkable. The alternative — trusting a number the
 * client sent — turns every board into an honour system.
 */
final readonly class SubmitRun
{
    /** @param list<int> $input */
    public function __construct(
        public string $runnerId,
        public string $releaseId,
        public string $scope,
        public ?string $targetId,
        public string $category,
        public int $ticks,
        public array $input,
        public int $seed,
        public string $rulesVersion,
    ) {
    }
}
