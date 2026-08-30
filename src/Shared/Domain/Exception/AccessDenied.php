<?php

declare(strict_types=1);

namespace Wob\Shared\Domain\Exception;

final class AccessDenied extends DomainException
{
    public static function of(string $what, string $id): self
    {
        return new self(sprintf("%s %s does not belong to you", $what, $id));
    }
}
