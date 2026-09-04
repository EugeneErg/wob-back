<?php

declare(strict_types=1);

namespace Wob\Library\Application\Handler;

use Wob\Library\Application\Command\SaveChapterMap;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\Repository\StoryRepository;
use Wob\Library\Domain\ValueObject\ChapterId;
use Wob\Library\Domain\ValueObject\LevelId;
use Wob\Library\Domain\ValueObject\CanvasRect;
use Wob\Library\Domain\ValueObject\MapNode;
use Wob\Library\Domain\ValueObject\NodeId;
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

        if ($command->map !== null) {
            $chapter->setMap($command->map);
        }

        if ($command->canvas !== null) {
            $chapter->placeOnCanvas(new CanvasRect(
                (float) $command->canvas['x'],
                (float) $command->canvas['y'],
                (float) $command->canvas['w'],
                (float) $command->canvas['h'],
            ));
        }

        $nodes = array_map(
            static fn (array $n): MapNode => new MapNode(
                // An editor that has not been taught about point ids yet still
                // sends maps without them. Deriving one from the level keeps
                // those saves working and matches what hydration does to rows
                // written before points had names.
                new NodeId($n["id"] ?? 'nd-' . $n["levelId"]),
                new LevelId($n["levelId"]),
                (float) $n["x"],
                (float) $n["y"],
                array_map(static fn (string $c): NodeId => new NodeId($c), $n["next"] ?? []),
                (string) ($n["name"] ?? ''),
                (string) ($n["image"] ?? ''),
                (string) ($n["outro"] ?? ''),
            ),
            $command->nodes,
        );

        $story->replaceChapterMap(new ChapterId($command->chapterId), $nodes);

        // Levels that fell off the map during this edit are now unreachable.
        // Saving through the aggregate is what catches that: it re-checks that
        // every node still points at a level this story actually has.
        $this->stories->save($story);

        return $story;
    }
}
