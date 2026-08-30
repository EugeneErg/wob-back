<?php

declare(strict_types=1);

namespace Wob\Shared\Infrastructure\Laravel;

use Illuminate\Contracts\Events\Dispatcher;
use Wob\Shared\Domain\DomainEvent;
use Wob\Shared\Domain\DomainEventBus;

final readonly class LaravelDomainEventBus implements DomainEventBus
{
    public function __construct(private Dispatcher $dispatcher)
    {
    }

    public function publish(DomainEvent $event): void
    {
        // Dispatched under the event class name, so a listener subscribes to the
        // domain event itself and never to a framework wrapper around it.
        $this->dispatcher->dispatch($event);
    }

    public function publishAll(array $events): void
    {
        foreach ($events as $event) {
            $this->publish($event);
        }
    }
}
