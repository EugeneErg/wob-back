<?php

declare(strict_types=1);

namespace Wob\Identity\Domain\Event;

use DateTimeImmutable;
use Wob\Identity\Domain\Model\UserId;
use Wob\Shared\Domain\DomainEvent;

/**
 * First sign-in. Library listens for it to seed the built-in story, so that a
 * brand-new account opens on something playable rather than an empty shelf.
 */
final readonly class UserRegistered implements DomainEvent
{
    public function __construct(public UserId $userId, private DateTimeImmutable $at)
    {
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->at;
    }
}
