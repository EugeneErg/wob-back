<?php

declare(strict_types=1);

namespace Wob\Library\Application\Handler;

use Wob\Library\Application\Command\UpdateStory;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\Repository\StoryRepository;
use Wob\Library\Domain\ValueObject\AssetId;
use Wob\Library\Domain\ValueObject\Backdrop;
use Wob\Library\Domain\ValueObject\CanvasRect;
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

        if ($command->backdrop !== null) {
            $src = trim((string) $command->backdrop['src']);

            // Пустая картинка — это «задника нет», а не задник с пустым путём:
            // то же правило, по которому его читают из строки базы.
            $story->setBackdrop($src === '' ? null : new Backdrop($src, new CanvasRect(
                (float) $command->backdrop['x'],
                (float) $command->backdrop['y'],
                (float) $command->backdrop['w'],
                (float) $command->backdrop['h'],
            )));
        }

        if ($command->chapterOrder !== null) {
            $story->reorderChapters(array_map(static fn (string $id): ChapterId => new ChapterId($id), $command->chapterOrder));
        }

        $this->stories->save($story);

        return $story;
    }
}
