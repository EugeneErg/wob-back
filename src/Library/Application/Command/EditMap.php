<?php

declare(strict_types=1);

namespace Wob\Library\Application\Command;

/**
 * Одна мелкая правка карты.
 *
 * Три вида в одной команде, потому что это один жест автора с разных сторон:
 * переименовать главу, подвинуть или подписать точку, провести или снять связь.
 * Разводить их на три команды и три обработчика значило бы трижды повторить
 * «найди историю, проверь владельца, сохрани».
 *
 * Версии здесь нет намеренно. Правка касается одного объекта, поэтому спорить
 * ей не с чем: разные точки не пересекаются, одна и та же сходится к последней.
 */
final readonly class EditMap
{
    private function __construct(
        public string $ownerId,
        public string $storyId,
        public string $kind,
        public ?string $chapterId = null,
        public ?string $nodeId = null,
        public ?float $x = null,
        public ?float $y = null,
        public ?string $name = null,
        public ?string $image = null,
        public ?string $outro = null,
        public ?string $from = null,
        public ?string $to = null,
        public bool $linked = true,
    ) {
    }

    public static function chapter(
        string $ownerId,
        string $storyId,
        string $chapterId,
        string $title,
        string $image,
        string $map,
    ): self {
        return new self($ownerId, $storyId, "chapter", $chapterId, name: $title, image: $image, outro: $map);
    }

    public static function node(
        string $ownerId,
        string $storyId,
        string $chapterId,
        string $nodeId,
        ?float $x,
        ?float $y,
        ?string $name,
        ?string $image,
        ?string $outro,
    ): self {
        return new self($ownerId, $storyId, "node", $chapterId, $nodeId, $x, $y, $name, $image, $outro);
    }

    public static function link(string $ownerId, string $storyId, string $from, string $to, bool $linked): self
    {
        return new self($ownerId, $storyId, "link", from: $from, to: $to, linked: $linked);
    }
}
