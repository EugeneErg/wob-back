<?php

declare(strict_types=1);

namespace Wob\Library\Application\Handler;

use Wob\Library\Application\Command\CreateChapter;
use Wob\Library\Domain\Model\Chapter;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\Repository\StoryRepository;
use Wob\Library\Domain\ValueObject\ChapterId;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;

final readonly class CreateChapterHandler
{
    public function __construct(private StoryRepository $stories)
    {
    }

    public function __invoke(CreateChapter $command): Story
    {
        $owner = new OwnerId($command->ownerId);
        $story = $this->stories->get($owner, new StoryId($command->storyId));
        $story->addChapter(new Chapter(new ChapterId($command->chapterId), $command->title, $command->image));
        $this->stories->save($story);

        return $story;
    }
}
