<?php

declare(strict_types=1);

namespace Wob\Library\Application\Command;

/**
 * The chapter map, replaced whole: node positions, exits and paths in one go.
 * Dragging a node and drawing a path are the same gesture to the author, and
 * splitting them into two requests would let the map be saved half-moved.
 *
 */
final readonly class SaveChapterMap
{
    /**
     * Форма узла описана целиком, а не наполовину.
     *
     * Раньше здесь были перечислены только `levelId`, `x`, `y` и `next`, тогда
     * как обработчик читает ещё и `id`, `name`, `image`, `outro`. Разошлось это
     * молча: описание не проверяется само по себе, а обработчик просто брал
     * недостающее через `??`. Заметно стало только по разбору кода — он и сказал,
     * что читаются поля, которых по описанию не бывает.
     *
     * Все четыре необязательные, и каждая по своей причине. `id` не присылает
     * редактор, не знающий об именах точек, — тогда имя выводится из уровня.
     * `name`, `image` и `outro` — украшения узла, и пустое значение у них
     * законно.
     *
     * @param list<array{
     *     levelId: string,
     *     x: float|int,
     *     y: float|int,
     *     id?: string,
     *     next?: list<string>,
     *     name?: string,
     *     image?: string,
     *     outro?: string,
     * }> $nodes
     */
    public function __construct(
        public string $ownerId,
        public string $storyId,
        public string $chapterId,
        public ?string $title,
        public ?string $image,
        public array $nodes,
        public ?string $map = null,

        /** @var array{x: float, y: float, w: float, h: float}|null */
        public ?array $canvas = null,
    ) {
    }
}
