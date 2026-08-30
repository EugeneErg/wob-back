<?php

declare(strict_types=1);

namespace Wob\Identity\Application\Exception;

use RuntimeException;

final class AuthenticationFailed extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
