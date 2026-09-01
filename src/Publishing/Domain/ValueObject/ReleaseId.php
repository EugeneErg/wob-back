<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\ValueObject;

use Ramsey\Uuid\Uuid;
use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * A release is a server-side object through and through — nothing about it is
 * minted offline the way a level id is, so unlike Library ids this one is a
 * plain UUID rather than a client-chosen string.
 */
final readonly class ReleaseId
{
    public function __construct(public string $value)
    {
        if (!Uuid::isValid($value)) {
            throw InvariantViolation::because('Release id must be a UUID');
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
