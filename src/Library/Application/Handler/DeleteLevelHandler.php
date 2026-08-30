<?php

declare(strict_types=1);

namespace Wob\Library\Application\Handler;

use Wob\Library\Application\Command\DeleteLevel;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\Repository\StoryRepository;
use Wob\Library\Domain\ValueObject\ChapterId;
use Wob\Library\Domain\ValueObject\LevelId;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Shared\Domain\DomainEventBus;

final readonly class DeleteLevelHandler
{
    public function __construct(private StoryRepository $stories, private DomainEventBus $events)
    {
    }

    public function __invoke(DeleteLevel $command): Story
    {
        $owner = new OwnerId($command->ownerId);
        $story = $this->stories->get($owner, new StoryId($command->storyId));
        $story->expectVersion($command->expectedVersion);
        $story->removeLevel(new ChapterId($command->chapterId), new LevelId($command->levelId));

        $this->stories->save($story);
        $this->events->publishAll($story->releaseEvents());

        return $story;
    }
}
