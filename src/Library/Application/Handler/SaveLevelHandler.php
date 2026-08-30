<?php

declare(strict_types=1);

namespace Wob\Library\Application\Handler;

use Wob\Library\Application\Command\SaveLevel;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\Repository\StoryRepository;
use Wob\Library\Domain\ValueObject\AssetId;
use Wob\Library\Domain\ValueObject\Dimensions;
use Wob\Library\Domain\ValueObject\EntityPlacement;
use Wob\Library\Domain\ValueObject\Gravity;
use Wob\Library\Domain\ValueObject\LevelId;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;

final readonly class SaveLevelHandler
{
    public function __construct(private StoryRepository $stories)
    {
    }

    public function __invoke(SaveLevel $command): Story
    {
        $owner = new OwnerId($command->ownerId);
        $story = $this->stories->get($owner, new StoryId($command->storyId));
        $story->expectVersion($command->expectedVersion);

        $level = $story->level(new LevelId($command->levelId));
        $level->rename($command->name);
        $level->resize(new Dimensions($command->width, $command->height));
        $level->setGravity(new Gravity($command->gravityX, $command->gravityY));
        $level->setGoal($command->goal);
        $level->replaceEntities(array_map(EntityPlacement::fromObject(...), $command->entities));
        $level->setHot(array_map(static fn (string $id): AssetId => new AssetId($id), $command->hot));

        $this->stories->save($story);

        return $story;
    }
}
