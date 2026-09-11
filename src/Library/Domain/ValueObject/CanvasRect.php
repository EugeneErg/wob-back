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
    private const MIN_SIDE = 80.0;

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

        // A chapter smaller than this cannot hold a point you could aim at, and
        // an area of zero size is invisible — dragged to nothing by accident and
        // impossible to grab back.
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
