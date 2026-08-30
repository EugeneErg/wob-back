<?php

declare(strict_types=1);

namespace Wob\Library\Application\Command;

final readonly class DeleteStory
{
    public function __construct(public string $ownerId, public string $storyId)
    {
    }
}
