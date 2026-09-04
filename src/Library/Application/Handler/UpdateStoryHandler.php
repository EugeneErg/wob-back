<?php

declare(strict_types=1);

namespace Wob\Library\Application\Handler;

use Wob\Library\Application\Command\UpdateStory;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\Repository\StoryRepository;
use Wob\Library\Domain\ValueObject\AssetId;
use Wob\Library\Domain\ValueObject\ChapterId;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;

/**
 * Null means "leave alone", not "clear". A PATCH that could not tell those apart
 * would make it impossible to rename a story without also resending its cover.
 */
final readonly class UpdateStoryHandler
{
    public function __construct(private StoryRepository $stories)
    {
    }

    public function __invoke(UpdateStory $command): Story
    {
        $owner = new OwnerId($command->ownerId);
        $story = $this->stories->get($owner, new StoryId($command->storyId));
        $story->expectVersion($command->expectedVersion);

        if ($command->title !== null) {
            $story->rename($command->title);
        }

        if ($command->cover !== null) {
            $story->setCover($command->cover);
        }

        if ($command->hot !== null) {
            $story->setHot(array_map(static fn (string $id): AssetId => new AssetId($id), $command->hot));
        }

        if ($command->intro !== null) {
            $story->setIntro($command->intro);
        }

        if ($command->startNodeId !== null) {
            $story->startOn($command->startNodeId === '' ? null : $command->startNodeId);
        }

        if ($command->chapterOrder !== null) {
            $story->reorderChapters(array_map(static fn (string $id): ChapterId => new ChapterId($id), $command->chapterOrder));
        }

        $this->stories->save($story);

        return $story;
    }
}
