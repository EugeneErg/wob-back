<?php

declare(strict_types=1);

namespace Wob\Library\Domain\ValueObject;

use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * A level pinned to the chapter map. x and y are percentages of the chapter
 * image, so the map keeps working whatever the picture size.
 *
 * "next" is the chapter this node leads out to. It may only point at a chapter
 * of the same story — a dangling exit is worse than no exit, because on the map
 * it still looks like a way forward.
 */
final readonly class MapNode
{
    public function __construct(
        public LevelId $levelId,
        public float $x,
        public float $y,
        public ?ChapterId $next = null,
    ) {
        foreach (["x" => $x, "y" => $y] as $name => $value) {
            if ($value < 0 || $value > 100) {
                throw InvariantViolation::because(
                    sprintf("Map node %s must be a percentage between 0 and 100, got %s", $name, $value),
                );
            }
        }
    }

    public function withNext(?ChapterId $next): self
    {
        return new self($this->levelId, $this->x, $this->y, $next);
    }
}
