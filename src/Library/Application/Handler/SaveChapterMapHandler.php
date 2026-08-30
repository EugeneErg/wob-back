<?php

declare(strict_types=1);

namespace Wob\Library\Application\Handler;

use Wob\Library\Application\Command\SaveChapterMap;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\Repository\StoryRepository;
use Wob\Library\Domain\ValueObject\ChapterId;
use Wob\Library\Domain\ValueObject\LevelId;
use Wob\Library\Domain\ValueObject\MapEdge;
use Wob\Library\Domain\ValueObject\MapNode;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;

final readonly class SaveChapterMapHandler
{
    public function __construct(private StoryRepository $stories)
    {
    }

    public function __invoke(SaveChapterMap $command): Story
    {
        $owner = new OwnerId($command->ownerId);
        $story = $this->stories->get($owner, new StoryId($command->storyId));
        $story->expectVersion($command->expectedVersion);

        $chapter = $story->chapter(new ChapterId($command->chapterId));

        if ($command->title !== null) {
            $chapter->rename($command->title);
        }

        if ($command->image !== null) {
            $chapter->setImage($command->image);
        }

        $nodes = array_map(
            static fn (array $n): MapNode => new MapNode(
                new LevelId($n["levelId"]),
                (float) $n["x"],
                (float) $n["y"],
                isset($n["next"]) && $n["next"] !== null ? new ChapterId($n["next"]) : null,
            ),
            $command->nodes,
        );

        $edges = array_map(
            static fn (array $e): MapEdge => new MapEdge(new LevelId($e["from"]), new LevelId($e["to"])),
            $command->edges,
        );

        $chapter->replaceMap($nodes, $edges);

        // Levels that fell off the map during this edit are now unreachable.
        // Saving through the aggregate is what catches that: it re-checks that
        // every node still points at a level this story actually has.
        $this->stories->save($story);

        return $story;
    }
}
