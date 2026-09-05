<?php

declare(strict_types=1);

namespace Wob\Library\Application\Handler;

use Wob\Library\Application\Command\DeleteChapter;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\Repository\StoryRepository;
use Wob\Library\Domain\ValueObject\ChapterId;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Shared\Domain\DomainEventBus;

final readonly class DeleteChapterHandler
{
    public function __construct(private StoryRepository $stories, private DomainEventBus $events)
    {
    }

    public function __invoke(DeleteChapter $command): Story
    {
        $owner = new OwnerId($command->ownerId);
        $story = $this->stories->get($owner, new StoryId($command->storyId));

        // Everything that follows from this — orphaned levels dropped, exits
        // that led here cleared — happens inside the aggregate. The handler
        // orchestrates; it does not decide.
        $story->removeChapter(new ChapterId($command->chapterId));

        $this->stories->save($story);
        $this->events->publishAll($story->releaseEvents());

        return $story;
    }
}
