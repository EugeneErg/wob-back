<?php

declare(strict_types=1);

namespace Wob\Library\Application\Command;

final readonly class UpdateStory
{
    /**
     * @param list<string>|null $hot
     * @param list<string>|null $chapterOrder
     */
    public function __construct(
        public string $ownerId,
        public string $storyId,
        public ?string $title,
        public ?string $cover,
        public ?array $hot,
        public ?array $chapterOrder,
        public int $expectedVersion,

        // Nullable means untouched, like everything above.
        public ?string $startNodeId = null,
        public ?string $intro = null,
    ) {
    }
}
