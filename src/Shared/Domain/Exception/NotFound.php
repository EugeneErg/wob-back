<?php

declare(strict_types=1);

namespace Wob\Shared\Domain\Exception;

final class NotFound extends DomainException
{
    public static function of(string $what, string $id): self
    {
        return new self(sprintf("%s %s does not exist", $what, $id));
    }
}
