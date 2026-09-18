<?php

declare(strict_types=1);

namespace Wob\Library\Domain\ValueObject;

use JsonSerializable;
use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * Where a chapter sits on the story board.
 *
 * In board units, not percentages, and the board has no edges: a story grows by
 * putting the next chapter further out, and a canvas that clamped its contents
 * would start refusing arrangements halfway through one.
 *
 * The points inside a chapter keep their own coordinates as percentages of that
 * chapter. Two spaces rather than one, on purpose — dragging a chapter across
 * the board then moves everything inside it without touching a single point.
 */
final readonly class CanvasRect implements JsonSerializable
{
    /**
     * Было 80, и это число мешало правде.
     *
     * Взялось оно из времён, когда глава на доске была прямоугольником под
     * точки, и означало «меньше в неё не прицелишься». Точек на доске нет:
     * глава — картинка, а величина её — величина этой картинки. Остров
     * «Механизм» в оригинале 76 единиц шириной, и порог в 80 отказывал бы
     * настоящему острову, требуя подрисовать ему четыре единицы из ниоткуда.
     *
     * Прицеливаться мешает не размер, а соотношение: доска подгоняется под своё
     * содержимое, и глава в сто единиц рядом с главой в сто единиц занимает
     * половину экрана. Судить об этом внутри одной рамки нельзя — соседей она
     * не видит, — поэтому здесь остаётся то, что рамка знает про себя сама:
     * нулевой её быть нельзя. Нулевую не видно, её теряют движением руки и не
     * могут поймать обратно.
     */
    private const MIN_SIDE = 1.0;

    public function __construct(
        public float $x,
        public float $y,
        public float $w,
        public float $h,
    ) {
        foreach (["x" => $x, "y" => $y, "w" => $w, "h" => $h] as $name => $value) {
            if (!is_finite($value)) {
                throw InvariantViolation::because(sprintf("Canvas %s must be a number", $name));
            }
        }

        if ($w < self::MIN_SIDE || $h < self::MIN_SIDE) {
            throw InvariantViolation::because(
                sprintf("A chapter on the board must be at least %dx%d", (int) self::MIN_SIDE, (int) self::MIN_SIDE),
            );
        }
    }

    /**
     * Строка из базы — не объект с известными полями, а мешок свойств: чтение
     * `$row->canvas_x` разбору кода ничего не говорит. Поэтому поля берутся
     * через массив, где отсутствие названо явно.
     *
     * @param object|array<string, mixed> $row
     */
    public static function fromRow(object|array $row): self
    {
        $bag = (array) $row;

        return new self(
            (float) ($bag['canvas_x'] ?? 0),
            (float) ($bag['canvas_y'] ?? 0),
            (float) ($bag['canvas_w'] ?? 0),
            (float) ($bag['canvas_h'] ?? 0),
        );
    }

    /** @return array<string, float> */
    public function jsonSerialize(): array
    {
        return ["x" => $this->x, "y" => $this->y, "w" => $this->w, "h" => $this->h];
    }
}
