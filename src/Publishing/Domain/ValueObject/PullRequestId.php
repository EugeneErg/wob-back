<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\ValueObject;

use Ramsey\Uuid\Uuid;
use Wob\Shared\Domain\Exception\InvariantViolation;

final readonly class PullRequestId
{
    public function __construct(public string $value)
    {
        if (!Uuid::isValid($value)) {
            throw InvariantViolation::because('Pull request id must be a UUID');
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
