<?php

declare(strict_types=1);

namespace Wob\Publishing\Application\Command;

final readonly class OpenPullRequest
{
    public function __construct(
        public string $authorId,
        public string $forkStoryId,
        public string $title,
    ) {
    }
}
