<?php

declare(strict_types=1);

namespace Wob\Identity\Domain\Model;

use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * The "sub" claim: Google stable identifier for an account.
 *
 * Matching on email instead would be a security hole rather than a shortcut.
 * Emails change hands, and an account whose address was reassigned would let a
 * stranger walk into someone library.
 */
final readonly class GoogleSubject
{
    public function __construct(public string $value)
    {
        if ($value === "" || mb_strlen($value) > 255) {
            throw InvariantViolation::because("Google subject must be 1-255 characters");
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
