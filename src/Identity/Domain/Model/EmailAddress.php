<?php

declare(strict_types=1);

namespace Wob\Identity\Domain\Model;

use Wob\Shared\Domain\Exception\InvariantViolation;

final readonly class EmailAddress
{
    public function __construct(public string $value)
    {
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw InvariantViolation::because("Not a valid email address");
        }
    }
}
