<?php

declare(strict_types=1);

namespace Wob\Library\Application\Command;

final readonly class CreateLevel
{
    public function __construct(
        public string $ownerId,
        public string $storyId,
        public string $chapterId,
        public string $levelId,
        public string $name,
        public float $mapX,
        public float $mapY,
        public int $expectedVersion,
    ) {
    }
}
