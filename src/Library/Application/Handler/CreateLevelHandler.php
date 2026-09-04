<?php

declare(strict_types=1);

namespace Wob\Library\Application\Handler;

use Wob\Library\Application\Command\CreateLevel;
use Wob\Library\Domain\Model\Level;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\Repository\StoryRepository;
use Wob\Library\Domain\ValueObject\ChapterId;
use Wob\Library\Domain\ValueObject\Dimensions;
use Wob\Library\Domain\ValueObject\Gravity;
use Wob\Library\Domain\ValueObject\LevelId;
use Wob\Library\Domain\ValueObject\MapNode;
use Wob\Library\Domain\ValueObject\NodeId;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;

final readonly class CreateLevelHandler
{
    /** The defaults createLevel() uses on the client: one screen, ordinary gravity, three balls. */
    private const DEFAULT_WIDTH = 1600;
    private const DEFAULT_HEIGHT = 900;
    private const DEFAULT_GRAVITY_Y = 1800.0;
    private const DEFAULT_GOAL = 3;

    public function __construct(private StoryRepository $stories)
    {
    }

    public function __invoke(CreateLevel $command): Story
    {
        $owner = new OwnerId($command->ownerId);
        $story = $this->stories->get($owner, new StoryId($command->storyId));
        $story->expectVersion($command->expectedVersion);

        $levelId = new LevelId($command->levelId);
        $level = new Level(
            $levelId,
            $command->name,
            new Dimensions(self::DEFAULT_WIDTH, self::DEFAULT_HEIGHT),
            new Gravity(0.0, self::DEFAULT_GRAVITY_Y),
            self::DEFAULT_GOAL,
            [],
        );

        if ($command->chapterId === null) {
            $story->addSpareLevel($level);
        } else {
            $story->addLevel(
                new ChapterId($command->chapterId),
                $level,
                new MapNode(
                    new NodeId($command->nodeId ?? 'nd-' . $command->levelId),
                    $levelId,
                    $command->mapX,
                    $command->mapY,
                ),
            );
        }

        $this->stories->save($story);

        return $story;
    }
}
