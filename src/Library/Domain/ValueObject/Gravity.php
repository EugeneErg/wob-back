<?php

declare(strict_types=1);

namespace Wob\Library\Domain\ValueObject;

/**
 * The uniform part of the gravity field, in px/s^2. Point sources live inside
 * entity data and are none of the server business — see Level.
 */
final readonly class Gravity
{
    public function __construct(public float $x, public float $y)
    {
    }

    /** @param array{x?: float|int, y?: float|int} $raw */
    public static function fromArray(array $raw): self
    {
        return new self((float) ($raw["x"] ?? 0), (float) ($raw["y"] ?? 1800));
    }

    /** @return array{x: float, y: float} */
    public function toArray(): array
    {
        return ["x" => $this->x, "y" => $this->y];
    }
}
