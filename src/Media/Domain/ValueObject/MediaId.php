<?php

declare(strict_types=1);

namespace Wob\Media\Domain\ValueObject;

use Ramsey\Uuid\Uuid;
use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * Server-issued, unlike the ids in Library.
 *
 * Content ids come from the client because the editor has to name a level
 * before it can save one. Media has no such problem: the bytes arrive already
 * complete, so the id is minted here and the client never gets to choose it.
 * That also means one upload can never land on top of an earlier one.
 */
final readonly class MediaId
{
    public function __construct(public string $value)
    {
        if (!Uuid::isValid($value)) {
            throw InvariantViolation::because("Media id must be a UUID");
        }
    }

    public static function generate(): self
    {
        return new self(Uuid::uuid4()->toString());
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
