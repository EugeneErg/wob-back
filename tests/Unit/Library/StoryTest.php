<?php

declare(strict_types=1);

namespace Wob\Tests\Unit\Library;

use PHPUnit\Framework\TestCase;
use Wob\Library\Domain\Event\LevelsDiscarded;
use Wob\Library\Domain\Model\Chapter;
use Wob\Library\Domain\Model\Level;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\ValueObject\ChapterId;
use Wob\Library\Domain\ValueObject\Dimensions;
use Wob\Library\Domain\ValueObject\Gravity;
use Wob\Library\Domain\ValueObject\LevelId;
use Wob\Library\Domain\ValueObject\MapEdge;
use Wob\Library\Domain\ValueObject\MapNode;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Shared\Domain\Exception\ConcurrentModification;
use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * The rules that made Story an aggregate in the first place. Every test here is
 * a bug that flat tables and free functions would have let through.
 */
final class StoryTest extends TestCase
{
    private const OWNER = '3f2504e0-4f89-41d3-9a0c-0305e82c3301';

    public function testAPathCannotPointAtALevelThatIsNotOnTheMap(): void
    {
        $story = $this->storyWith(['a', 'b']);
        $chapter = $story->chapter(new ChapterId('ch-1'));

        $this->expectException(InvariantViolation::class);

        $chapter->replaceMap(
            [new MapNode(new LevelId('lvl-a'), 10, 10)],
            [new MapEdge(new LevelId('lvl-a'), new LevelId('lvl-b'))],
        );
    }

    /**
     * And the map must not be left half-applied when it is rejected. A partially
     * saved map is worse than a refused one: it looks fine and gates the wrong
     * levels.
     */
    public function testARejectedMapLeavesTheOldOneIntact(): void
    {
        $story = $this->storyWith(['a', 'b']);
        $chapter = $story->chapter(new ChapterId('ch-1'));
        $before = count($chapter->nodes());

        try {
            $chapter->replaceMap(
                [new MapNode(new LevelId('lvl-a'), 10, 10)],
                [new MapEdge(new LevelId('lvl-a'), new LevelId('lvl-b'))],
            );
        } catch (InvariantViolation) {
            // expected
        }

        self::assertCount($before, $chapter->nodes());
    }

    public function testDeletingAChapterDropsTheLevelsNobodyElseUses(): void
    {
        $story = $this->storyWith(['a', 'b']);
        $story->removeChapter(new ChapterId('ch-1'));

        self::assertSame([], $story->levels());
        self::assertInstanceOf(LevelsDiscarded::class, $story->releaseEvents()[0]);
    }

    /** A hub level pinned to two maps survives losing one of them. */
    public function testASharedLevelSurvivesDeletingOneChapter(): void
    {
        $story = $this->storyWith(['a', 'b']);
        $second = new Chapter(new ChapterId('ch-2'), 'Second', '#000');
        $story->addChapter($second);
        $second->pin(new MapNode(new LevelId('lvl-a'), 50, 50));

        $story->removeChapter(new ChapterId('ch-1'));

        self::assertCount(1, $story->levels());
        self::assertSame('lvl-a', $story->levels()[0]->id->value);
    }

    /**
     * An exit into a chapter that is gone would draw a node that looks like a
     * way forward, and would let the chapter count as finished through a road
     * that does not exist.
     */
    public function testDeletingAChapterClearsTheExitsThatLedIntoIt(): void
    {
        $story = $this->storyWith(['a']);
        $second = new Chapter(new ChapterId('ch-2'), 'Second', '#000');
        $story->addChapter($second);

        $story->chapter(new ChapterId('ch-1'))->replaceMap(
            [new MapNode(new LevelId('lvl-a'), 10, 10, new ChapterId('ch-2'))],
            [],
        );

        $story->removeChapter(new ChapterId('ch-2'));

        self::assertNull($story->chapter(new ChapterId('ch-1'))->nodes()[0]->next);
    }

    public function testAStoryCannotBeBuiltWithAChapterPointingOutsideIt(): void
    {
        $this->expectException(InvariantViolation::class);

        new Story(
            new StoryId('story-1'),
            new OwnerId(self::OWNER),
            'Story',
            '#000',
            [new Chapter(new ChapterId('ch-1'), 'One', '#000', [new MapNode(new LevelId('lvl-ghost'), 1, 1)])],
            [],
        );
    }

    public function testAStaleWriteIsRefusedRatherThanApplied(): void
    {
        $story = $this->storyWith(['a']);
        $story->bumpVersion();
        $story->bumpVersion();

        $this->expectException(ConcurrentModification::class);

        $story->expectVersion(1);
    }

    /** @param list<string> $levelSuffixes */
    private function storyWith(array $levelSuffixes): Story
    {
        $levels = [];
        $nodes = [];
        $x = 10.0;

        foreach ($levelSuffixes as $suffix) {
            $id = new LevelId('lvl-' . $suffix);
            $levels[] = new Level($id, 'Level ' . $suffix, new Dimensions(1600, 900), new Gravity(0, 1800), 3, []);
            $nodes[] = new MapNode($id, $x, 50.0);
            $x += 10.0;
        }

        return new Story(
            new StoryId('story-1'),
            new OwnerId(self::OWNER),
            'Story',
            '#000',
            [new Chapter(new ChapterId('ch-1'), 'One', '#000', $nodes)],
            $levels,
        );
    }
}
