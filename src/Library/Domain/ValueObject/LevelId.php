<?php

declare(strict_types=1);

namespace Wob\Library\Domain\ValueObject;

final readonly class LevelId extends ClientId
{
    public const PREFIX = "lvl";

    protected static function label(): string
    {
        return "Level id";
    }
}
