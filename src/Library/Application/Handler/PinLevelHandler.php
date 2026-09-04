<?php

declare(strict_types=1);

namespace Wob\Library\Application\Handler;

use Wob\Library\Application\Command\PinLevel;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\Repository\StoryRepository;
use Wob\Library\Domain\ValueObject\ChapterId;
use Wob\Library\Domain\ValueObject\LevelId;
use Wob\Library\Domain\ValueObject\MapNode;
use Wob\Library\Domain\ValueObject\NodeId;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;

final readonly class PinLevelHandler
{
    public function __construct(private StoryRepository $stories)
    {
    }

    public function __invoke(PinLevel $command): Story
    {
        $owner = new OwnerId($command->ownerId);
        $story = $this->stories->get($owner, new StoryId($command->storyId));
        $story->expectVersion($command->expectedVersion);

        $levelId = new LevelId($command->levelId);

        // Ставить можно только то, что в истории уже есть: точка показывает
        // уровень, а не заводит его. level() сам бросит NotFound, если его нет.
        $story->level($levelId);

        $chapter = $story->chapter(new ChapterId($command->chapterId));
        $chapter->pin(new MapNode(
            new NodeId($command->nodeId),
            $levelId,
            $command->mapX,
            $command->mapY,
        ));

        $this->stories->save($story);

        return $story;
    }
}
