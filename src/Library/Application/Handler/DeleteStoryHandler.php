<?php

declare(strict_types=1);

namespace Wob\Library\Application\Handler;

use Wob\Library\Application\Command\DeleteStory;
use Wob\Library\Domain\Repository\StoryRepository;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Shared\Domain\DomainEventBus;

final readonly class DeleteStoryHandler
{
    public function __construct(private StoryRepository $stories, private DomainEventBus $events)
    {
    }

    public function __invoke(DeleteStory $command): void
    {
        $owner = new OwnerId($command->ownerId);
        $story = $this->stories->get($owner, new StoryId($command->storyId));

        $story->delete();
        $this->stories->remove($story);
        $this->events->publishAll($story->releaseEvents());
    }
}
