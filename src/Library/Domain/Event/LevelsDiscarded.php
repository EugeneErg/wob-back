<?php

declare(strict_types=1);

namespace Wob\Library\Domain\Event;

use DateTimeImmutable;
use Wob\Library\Domain\ValueObject\LevelId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Shared\Domain\DomainEvent;

/** Levels that lost their last chapter map and were dropped with it. */
final readonly class LevelsDiscarded implements DomainEvent
{
    private DateTimeImmutable $at;

    /** @param list<LevelId> $levelIds */
    public function __construct(public StoryId $storyId, public array $levelIds)
    {
        $this->at = new DateTimeImmutable();
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->at;
    }
}
