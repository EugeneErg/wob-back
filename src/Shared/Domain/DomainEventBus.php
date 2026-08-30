<?php

declare(strict_types=1);

namespace Wob\Shared\Domain;

/**
 * How a context announces what happened without naming who listens.
 *
 * The interface lives in the domain so repositories can depend on it; the
 * implementation is Laravel event dispatcher. Contexts subscribe in their own
 * service providers, which keeps the wiring next to the code that cares.
 */
interface DomainEventBus
{
    public function publish(DomainEvent $event): void;

    /** @param list<DomainEvent> $events */
    public function publishAll(array $events): void;
}
