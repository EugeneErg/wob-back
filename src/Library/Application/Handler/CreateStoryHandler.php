<?php

declare(strict_types=1);

namespace Wob\Library\Application\Handler;

use Wob\Library\Application\Command\CreateStory;
use Wob\Library\Domain\Model\Chapter;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\Repository\StoryRepository;
use Wob\Library\Domain\ValueObject\ChapterId;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * A story is created with its first chapter, the way createStory() already does
 * on the client. A story with no chapters has nothing to show and no way to add
 * a level, so it is not a state worth being able to reach.
 */
final readonly class CreateStoryHandler
{
    public function __construct(private StoryRepository $stories)
    {
    }

    public function __invoke(CreateStory $command): Story
    {
        $id = new StoryId($command->storyId);
        $owner = new OwnerId($command->ownerId);

        // The id came from the editor, so it can collide with one that is
        // already here — a second device, or a re-sent request. Refusing is
        // right: silently adopting the existing story would hand the caller
        // someone else content under an id they believe is theirs.
        if ($this->stories->find($owner, $id) !== null) {
            throw InvariantViolation::because(sprintf("Story %s already exists", $id->value));
        }

        $story = new Story(
            $id,
            $owner,
            $command->title,
            $command->cover,
            [new Chapter(new ChapterId($command->firstChapterId), $command->chapterTitle, $command->chapterImage)],
        );

        $this->stories->save($story);

        return $story;
    }
}
