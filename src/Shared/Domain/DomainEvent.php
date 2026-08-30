<?php

declare(strict_types=1);

namespace Wob\Shared\Domain;

use DateTimeImmutable;

/**
 * Something that happened in the domain and that other contexts may care about.
 *
 * Events are how bounded contexts talk without depending on each other. Progress
 * must not import a Library class to learn that a story was deleted; it listens
 * for StoryDeleted and reacts. The moment one context type-hints another context
 * internal class, the boundary is gone.
 */
interface DomainEvent
{
    public function occurredAt(): DateTimeImmutable;
}
