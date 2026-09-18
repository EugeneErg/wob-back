<?php

declare(strict_types=1);

namespace Wob\Library\Domain\ValueObject;

use JsonSerializable;
use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * Картинка позади глав и место, где она лежит.
 *
 * Обложка истории — это карточка в списке: её растягивают под карточку, и где
 * что на ней, значения не имеет. Задник — другое: главы стоят на нём, и стоят в
 * тех же единицах доски. В оригинале пять островов лежат по ободу планеты, и
 * промах на десяток единиц виден сразу — остров висит в пустоте.
 *
 * Поэтому картинка и рамка здесь одно, а не два поля рядом. Картинка без места
 * — это вопрос «куда её класть», на который никто не ответит; место без
 * картинки — пустая рамка. Порознь они бессмысленны, а значит и появляться
 * должны вместе.
 *
 * Это оформление: в содержимое истории задник не входит и на хеш не влияет. Тут
 * он в одном ряду с обложкой и заставкой, а не с главами.
 */
final readonly class Backdrop implements JsonSerializable
{
    public function __construct(public string $src, public CanvasRect $at)
    {
        if (trim($src) === '') {
            throw InvariantViolation::because('A backdrop needs a picture');
        }

        if (mb_strlen($src) > 2000) {
            throw InvariantViolation::because('Story backdrop is too long');
        }
    }

    /**
     * Задник из строки базы, если он там есть.
     *
     * Пустая картинка — это «задника нет», а не «задник со сломанным путём»:
     * строка без него существовала до того, как поле появилось, и всех таких
     * строк большинство.
     *
     * @param object|array<string, mixed> $row
     */
    public static function fromRow(object|array $row): ?self
    {
        $bag = (array) $row;
        $src = trim((string) ($bag['backdrop'] ?? ''));

        if ($src === '') {
            return null;
        }

        return new self($src, new CanvasRect(
            (float) ($bag['backdrop_x'] ?? 0),
            (float) ($bag['backdrop_y'] ?? 0),
            (float) ($bag['backdrop_w'] ?? 0),
            (float) ($bag['backdrop_h'] ?? 0),
        ));
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return ['src' => $this->src, 'x' => $this->at->x, 'y' => $this->at->y, 'w' => $this->at->w, 'h' => $this->at->h];
    }
}
