<?php

declare(strict_types=1);

namespace Wob\Library\Application\Command;

final readonly class CreateChapter
{
    public function __construct(
        public string $ownerId,
        public string $storyId,
        public string $chapterId,
        public string $title,
        public string $image,
        public int $expectedVersion,
    ) {
    }
}
