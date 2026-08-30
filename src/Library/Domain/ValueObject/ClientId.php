<?php

declare(strict_types=1);

namespace Wob\Library\Domain\ValueObject;

use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * An identifier minted by the editor, not by the database.
 *
 * The level editor has always worked offline: library.js builds ids like
 * "lvl-k3f9a2" the moment you press a button, and the whole level graph — map
 * nodes, edges, hot asset lists — points at them immediately. If the server
 * insisted on issuing ids, every create would need a round trip before the
 * editor could draw anything, and an offline session could not be built at all.
 *
 * So the client id is the public identity of the thing, and the database keeps
 * a UUID of its own for foreign keys. Collisions are handled exactly the way
 * importBundle() already handles them on the client: the newcomer is renamed
 * and references are rewritten. Nothing is ever overwritten.
 */
abstract readonly class ClientId
{
    final public function __construct(public string $value)
    {
        if (preg_match("/^[A-Za-z0-9_-]{1,64}$/", $value) !== 1) {
            throw InvariantViolation::because(
                sprintf("%s must be 1-64 characters of [A-Za-z0-9_-], got \"%s\"", static::label(), $value),
            );
        }
    }

    abstract protected static function label(): string;

    public function equals(self $other): bool
    {
        return $this::class === $other::class && $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
