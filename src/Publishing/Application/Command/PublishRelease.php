<?php

declare(strict_types=1);

namespace Wob\Publishing\Application\Command;

final readonly class PublishRelease
{
    public function __construct(
        public string $ownerId,
        public string $storyId,
    ) {
    }
}
