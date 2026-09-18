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

        // Nullable means untouched, like everything above.
        public ?string $startNodeId = null,
        public ?string $intro = null,

        /**
         * Картинка позади глав вместе со своим местом.
         *
         * Целиком, а не пятью полями: главы стоят на ней в её же единицах доски,
         * и картинка без места — вопрос «куда её класть», а место без картинки —
         * пустая рамка. Порознь они бессмысленны, значит и приезжать должны
         * вместе.
         *
         * `null` — не трогать, как и всё выше. Пустая картинка — убрать.
         *
         * Картинка бывает null, а не только пустой строкой: пустую строку
         * приложение превращает в null посредником, ещё до правил проверки, —
         * и «убрать задник» приезжает сюда именно так.
         *
         * @var array{src: string|null, x: float|int, y: float|int, w: float|int, h: float|int}|null
         */
        public ?array $backdrop = null,
    ) {
    }
}
