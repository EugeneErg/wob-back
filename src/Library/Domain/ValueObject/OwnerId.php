<?php

declare(strict_types=1);

namespace Wob\Library\Domain\ValueObject;

use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * Who the story belongs to.
 *
 * Deliberately NOT Identity\Domain\Model\UserId. Library needs to know that
 * content has an owner; it does not need to know that an owner has a Google
 * subject and an avatar. Importing the Identity model here would make the two
 * contexts one, and the first time authentication changes, content would have
 * to be redeployed with it. The two ids carry the same UUID string, and that is
 * the entire contract between them.
 */
final readonly class OwnerId
{
    public function __construct(public string $value)
    {
        if (preg_match("/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i", $value) !== 1) {
            throw InvariantViolation::because("Owner id must be a UUID");
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
