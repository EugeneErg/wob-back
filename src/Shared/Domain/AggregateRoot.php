<?php

declare(strict_types=1);

namespace Wob\Shared\Domain;

/**
 * An aggregate root is the only object the outside world is allowed to hold a
 * reference to, and the only unit that is loaded and saved as a whole.
 *
 * Why it matters here: a Story owns its chapters, levels and map edges, and the
 * rules that keep them coherent ("an edge may only join levels that are on the
 * map", "deleting a chapter drops the levels nobody else uses") are rules ABOUT
 * THE WHOLE. If chapters could be saved independently, those rules would have
 * nowhere to live and would leak into controllers, where nothing enforces them.
 */
abstract class AggregateRoot
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];

    protected function recordThat(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /**
     * Events are pulled once, by the repository, at save time. Draining rather
     * than reading keeps a second save from publishing the same event twice.
     *
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }
}
