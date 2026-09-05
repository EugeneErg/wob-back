<?php

declare(strict_types=1);

namespace Wob\Library\Application\Command;

final readonly class CreateLevel
{
    public function __construct(
        public string $ownerId,
        public string $storyId,
        // null — уровень пока нигде не лежит: место выберут перетаскиванием.
        public ?string $chapterId,
        public string $levelId,
        public string $name,
        public float $mapX,
        public float $mapY,

        // The editor may name the point itself. When it does not, the handler
        // derives one from the level, which is the only sane default while a
        // level still appears at exactly one point.
        public ?string $nodeId = null,
    ) {
    }
}
