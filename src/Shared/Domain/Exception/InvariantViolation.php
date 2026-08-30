<?php

declare(strict_types=1);

namespace Wob\Shared\Domain\Exception;

final class InvariantViolation extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
