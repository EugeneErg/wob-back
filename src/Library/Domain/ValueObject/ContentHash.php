<?php

declare(strict_types=1);

namespace Wob\Library\Domain\ValueObject;

use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * Eight hex characters of FNV-1a over a canonical serialisation — the same
 * fingerprint the client computes in core/releases.js.
 *
 * It is a version number that nobody has to remember to raise. Move one rock in
 * a level and the level hash changes, which changes the chapter hash, which
 * changes the story hash: a Merkle tree, exactly as the client comments say.
 * Iteration 1 uses it for change detection and ETags; releases and record
 * validation will lean on it far harder, which is precisely why it has to be
 * bit-identical to the client version from the very first commit.
 */
final readonly class ContentHash
{
    public function __construct(public string $value)
    {
        if (preg_match("/^[0-9a-f]{8}$/", $value) !== 1) {
            throw InvariantViolation::because("Content hash must be 8 lowercase hex characters");
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
