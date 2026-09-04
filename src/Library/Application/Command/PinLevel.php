<?php

declare(strict_types=1);

namespace Wob\Library\Application\Command;

/**
 * Поставить на карту главы ещё одну точку для уже существующего уровня.
 *
 * Отдельно от CreateLevel, потому что уровень при этом не создаётся: один и тот
 * же уровень может встречаться в истории несколько раз, и каждая встреча — своё
 * место со своим именем, своей картинкой и своим роликом.
 */
final readonly class PinLevel
{
    public function __construct(
        public string $ownerId,
        public string $storyId,
        public string $chapterId,
        public string $levelId,
        public string $nodeId,
        public float $mapX,
        public float $mapY,
        public int $expectedVersion,
    ) {
    }
}
