<?php

declare(strict_types=1);

namespace Wob\Library\Domain\ValueObject;

use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * A path on the chapter map. Direction carries meaning: a level unlocks when
 * any path leading into it has been finished, so from/to are not interchangeable.
 */
final readonly class MapEdge
{
    public function __construct(public LevelId $from, public LevelId $to)
    {
        if ($from->equals($to)) {
            throw InvariantViolation::because("A path cannot lead from a level to itself");
        }
    }
}
