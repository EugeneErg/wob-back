<?php

declare(strict_types=1);

namespace Wob\Library\Domain\ValueObject;

use Wob\Shared\Domain\Exception\InvariantViolation;

final readonly class Dimensions
{
    private const MIN = 100;
    private const MAX = 40000;

    public function __construct(public int $width, public int $height)
    {
        foreach (["width" => $width, "height" => $height] as $name => $value) {
            if ($value < self::MIN || $value > self::MAX) {
                throw InvariantViolation::because(
                    sprintf("Level %s must be between %d and %d px, got %d", $name, self::MIN, self::MAX, $value),
                );
            }
        }
    }
}
