<?php

declare(strict_types=1);

namespace Wob\Publishing\Application\Command;

final readonly class DecidePullRequest
{
    public const ACCEPT = 'accept';
    public const REJECT = 'reject';
    public const WITHDRAW = 'withdraw';

    public function __construct(
        public string $actorId,
        public string $pullRequestId,
        public string $decision,
    ) {
    }
}
