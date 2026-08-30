<?php

declare(strict_types=1);

namespace Wob\Library\Domain\Event;

use DateTimeImmutable;
use Wob\Library\Domain\ValueObject\LevelId;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Shared\Domain\DomainEvent;

/**
 * Progress listens for this: completions of levels that no longer exist are
 * dead weight. Library does not call Progress — it says what happened and stops
 * caring who reacts.
 */
final readonly class StoryDeleted implements DomainEvent
{
    private DateTimeImmutable $at;

    /** @param list<LevelId> $levelIds */
    public function __construct(
        public StoryId $storyId,
        public OwnerId $ownerId,
        public array $levelIds,
    ) {
        $this->at = new DateTimeImmutable();
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->at;
    }
}
