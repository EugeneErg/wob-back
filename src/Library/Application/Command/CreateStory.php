<?php

declare(strict_types=1);

namespace Wob\Library\Application\Command;

/**
 * Commands are plain data with no behaviour and no framework types. A Request
 * object here would tie the use case to HTTP and stop a console command or a
 * test from ever invoking it.
 */
final readonly class CreateStory
{
    public function __construct(
        public string $ownerId,
        public string $storyId,
        public string $firstChapterId,
        public string $title,
        public string $cover,
        public string $chapterTitle,
        public string $chapterImage,
    ) {
    }
}
