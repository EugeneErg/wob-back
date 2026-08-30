<?php

declare(strict_types=1);

namespace Wob\Identity\Domain\Model;

use Ramsey\Uuid\Uuid;
use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * Ours, not Google. A user who signs in with Apple tomorrow, or with a password,
 * is the same person and must keep the same id — so identity cannot be the
 * Google subject, or every story ever authored would change hands on the day a
 * second provider is added.
 */
final readonly class UserId
{
    public function __construct(public string $value)
    {
        if (!Uuid::isValid($value)) {
            throw InvariantViolation::because("User id must be a UUID");
        }
    }

    public static function next(): self
    {
        return new self(Uuid::uuid4()->toString());
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
