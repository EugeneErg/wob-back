<?php

declare(strict_types=1);

namespace Wob\Library\Domain\ValueObject;

final readonly class AssetId extends ClientId
{
    public const PREFIX = "as";

    protected static function label(): string
    {
        return "Asset id";
    }
}
