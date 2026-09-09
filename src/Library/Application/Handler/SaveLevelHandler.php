<?php

declare(strict_types=1);

namespace Wob\Library\Application\Handler;

use Wob\Library\Application\Command\SaveLevel;
use Wob\Library\Application\Import\AssetReferences;
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
    public function __construct(
        private StoryRepository $stories,
        private AssetReferences $assets,
    ) {
    }

    public function __invoke(SaveLevel $command): Story
    {
        $owner = new OwnerId($command->ownerId);
        $story = $this->stories->get($owner, new StoryId($command->storyId));

        $level = $story->level(new LevelId($command->levelId));
        $level->rename($command->name);
        $level->resize(new Dimensions($command->width, $command->height));
        $level->setGravity(new Gravity($command->gravityX, $command->gravityY));
        $level->setGoal($command->goal);
        $entities = array_map(EntityPlacement::fromObject(...), $command->entities);
        // Refuse the save rather than store a level nobody can open: an asset
        // that does not resolve is a break upstream, not a case to smooth over.
        $this->assets->mustResolve($entities);
        $level->replaceEntities($entities);
        $level->setHot(array_map(static fn (string $id): AssetId => new AssetId($id), $command->hot));

        if ($command->image !== null) {
            $level->setImage($command->image);
        }

        $this->stories->save($story);

        return $story;
    }
}
