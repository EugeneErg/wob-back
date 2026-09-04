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

    public static function fromRow(object $row): self
    {
        return new self(
            (float) $row->canvas_x,
            (float) $row->canvas_y,
            (float) $row->canvas_w,
            (float) $row->canvas_h,
        );
    }

    /** @return array<string, float> */
    public function jsonSerialize(): array
    {
        return ["x" => $this->x, "y" => $this->y, "w" => $this->w, "h" => $this->h];
    }
}
