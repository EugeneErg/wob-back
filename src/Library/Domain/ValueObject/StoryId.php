<?php

declare(strict_types=1);

namespace Wob\Library\Domain\ValueObject;

final readonly class StoryId extends ClientId
{
    public const PREFIX = "story";

    protected static function label(): string
    {
        return "Story id";
    }
}
