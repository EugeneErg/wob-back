<?php

declare(strict_types=1);

namespace Wob\Library\Domain\ValueObject;

final readonly class ChapterId extends ClientId
{
    public const PREFIX = "ch";

    protected static function label(): string
    {
        return "Chapter id";
    }
}
