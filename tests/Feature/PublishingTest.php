<?php

declare(strict_types=1);

namespace Wob\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Wob\Library\Domain\Model\Chapter;
use Wob\Library\Domain\Model\Level;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\Repository\StoryRepository;
use Wob\Library\Domain\ValueObject\ChapterId;
use Wob\Library\Domain\ValueObject\Dimensions;
use Wob\Library\Domain\ValueObject\EntityPlacement;
use Wob\Library\Domain\ValueObject\Gravity;
use Wob\Library\Domain\ValueObject\LevelId;
use Wob\Library\Domain\ValueObject\MapNode;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Application\Command\PublishRelease;
use Wob\Publishing\Application\Handler\PublishReleaseHandler;
use Wob\Publishing\Application\Handler\ReevaluateCanonHandler;
use Wob\Publishing\Domain\Repository\ReleaseCompletionRepository;
use Wob\Publishing\Domain\Repository\ReleaseRepository;
use Wob\Publishing\Domain\Repository\VoteRepository;
use Wob\Publishing\Domain\Model\Vote;
use Wob\Publishing\Domain\ValueObject\Rating;
use Wob\Publishing\Domain\ValueObject\RouteCompletion;
use Wob\Tests\TestCase;

/**
 * Publishing, voting and canon, against a real Postgres.
 *
 * The thing worth proving here is the whole arc rather than any one step: a
 * release freezes, opinions attach to it, the crown moves when it is earned
 * and stays put when it is not, and editing a level costs an author exactly as
 * much standing as the edit was large.
 */
final class PublishingTest extends TestCase
{
    use RefreshDatabase;

    private string $authorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authorId = $this->makeUser('author@example.com');
    }

    public function testPublishingFreezesTheDraft(): void
    {
        $this->authorStory('story-1', chapters: 3, levelsEach: 3);

        $release = $this->publish('story-1');

        self::assertSame(1, $release->number);
        self::assertCount(3, $release->content->chapters);
        self::assertCount(9, $release->content->levels);

        // Editing the draft afterwards must not reach into the release: that
        // promise is the only reason a vote on it means anything.
        $story = $this->stories()->get(new OwnerId($this->authorId), new StoryId('story-1'));
        $story->level(new LevelId('lvl-1-1'))->rename('Renamed after release');
        $this->stories()->save($story);

        $reloaded = app(ReleaseRepository::class)->get($release->id);
        self::assertSame('Level 1-1', $reloaded->content->level('lvl-1-1')->name);
    }

    public function testPublishingTwiceWithoutChangesIsRefused(): void
    {
        $this->authorStory('story-1', 3, 3);
        $this->publish('story-1');

        $this->expectExceptionMessage('Nothing has changed');
        $this->publish('story-1');
    }

    public function testReleaseNumbersRunInSequence(): void
    {
        $this->authorStory('story-1', 3, 3);
        $first = $this->publish('story-1');

        $this->editLevel('story-1', 'lvl-1-1', 'Changed');
        $second = $this->publish('story-1');

        self::assertSame(1, $first->number);
        self::assertSame(2, $second->number);
        self::assertTrue($second->previousReleaseId?->equals($first->id));
    }

    /**
     * The crown is earned, not given. Every bar has to be cleared, and the
     * author has to have finished their own release first.
     */
    public function testAReleaseBecomesCanonOnlyWhenEveryBarIsCleared(): void
    {
        $this->authorStory('story-1', 3, 3);
        $release = $this->publish('story-1');

        $canon = app(ReevaluateCanonHandler::class);

        // Not cleared by its author yet.
        self::assertFalse($canon($release->id));

        $release->clearedByAuthor(now()->toDateTimeImmutable());
        app(ReleaseRepository::class)->save($release);

        // Cleared, but nobody has played it.
        self::assertFalse($canon($release->id));

        $this->giveQuorum($release->id, players: 149, rating: 9);
        self::assertFalse($canon($release->id), '149 is not 150');

        $this->giveQuorum($release->id, players: 150, rating: 9);
        self::assertTrue($canon($release->id));

        self::assertSame(
            $release->id->value,
            DB::table('stories')->where('public_id', 'story-1')->value('canonical_release_id'),
        );
    }

    public function testAHighlyPlayedButPoorlyRatedReleaseIsNotCanon(): void
    {
        $this->authorStory('story-1', 3, 3);
        $release = $this->publishAndClear('story-1');

        $this->giveQuorum($release->id, players: 300, rating: 7);

        self::assertFalse(app(ReevaluateCanonHandler::class)($release->id));
    }

    /** A story too thin to be a story cannot be crowned however popular it is. */
    public function testAThinStoryCannotBeCanon(): void
    {
        $this->authorStory('story-1', chapters: 2, levelsEach: 3);
        $release = $this->publishAndClear('story-1');

        $this->giveQuorum($release->id, players: 500, rating: 10);

        self::assertFalse(app(ReevaluateCanonHandler::class)($release->id));
    }

    /**
     * The crown never comes off. A canonical version 1 stays canonical while
     * version 2 gathers its own votes — players keep the version they were
     * told was good, and the author is never punished for publishing again.
     */
    public function testANewReleaseDoesNotUnseatTheCanonUntilItEarnsIt(): void
    {
        $this->authorStory('story-1', 3, 3);
        $first = $this->publishAndClear('story-1');
        $this->giveQuorum($first->id, 150, 9);

        self::assertTrue(app(ReevaluateCanonHandler::class)($first->id));

        $this->editLevel('story-1', 'lvl-1-1', 'Second version');
        $second = $this->publishAndClear('story-1');

        self::assertFalse(app(ReevaluateCanonHandler::class)($second->id), 'not yet earned');
        self::assertSame(
            $first->id->value,
            DB::table('stories')->where('public_id', 'story-1')->value('canonical_release_id'),
            'the old canon stays until the new one earns it',
        );

        $this->giveQuorum($second->id, 150, 9);
        self::assertTrue(app(ReevaluateCanonHandler::class)($second->id));

        self::assertSame(
            $second->id->value,
            DB::table('stories')->where('public_id', 'story-1')->value('canonical_release_id'),
        );
    }

    /**
     * The heart of the design: an edit costs standing in proportion to its
     * size. A typo fix keeps a level's reputation; a rebuild does not.
     */
    public function testVotesCarryForwardInProportionToTheEdit(): void
    {
        $this->authorStory('story-1', 3, 3);
        $first = $this->publish('story-1');

        $this->voteOnLevel($first->id->value, 'lvl-1-1', 100, 9);
        $this->voteOnLevel($first->id->value, 'lvl-1-2', 100, 9);
        $this->voteOnLevel($first->id->value, 'lvl-2-1', 50, 8);

        // lvl-1-1 gets a cosmetic change; lvl-1-2 is rebuilt from scratch.
        $story = $this->stories()->get(new OwnerId($this->authorId), new StoryId('story-1'));
        $story->level(new LevelId('lvl-1-1'))->setGoal(4);
        $story->level(new LevelId('lvl-1-2'))->replaceEntities([
            $this->entity('brand-new-a', 'motor'),
            $this->entity('brand-new-b', 'motor'),
            $this->entity('brand-new-c', 'motor'),
        ]);
        $this->stories()->save($story);

        $second = $this->publish('story-1');
        $votes = app(VoteRepository::class);

        // Every opinion travels — none are thrown away — and each counts for
        // less in proportion to how much of the level changed.
        $nudged = $votes->forLevel($second->id, 'lvl-1-1');
        $rebuilt = $votes->forLevel($second->id, 'lvl-1-2');

        self::assertCount(100, $nudged, 'nothing is discarded');
        self::assertCount(100, $rebuilt, 'not even from a level rebuilt outright');

        self::assertGreaterThan(0.7, $nudged[0]->weight, 'a small edit should keep most of its standing');
        self::assertLessThan($nudged[0]->weight, $rebuilt[0]->weight, 'a rebuild should keep less than a nudge');

        // Untouched levels keep everything they had, at full weight.
        $untouched = $votes->forLevel($second->id, 'lvl-2-1');
        self::assertNotEmpty($untouched);
        self::assertSame(1.0, $untouched[0]->weight);
    }

    public function testANewLevelStartsWithNoInheritedOpinions(): void
    {
        $this->authorStory('story-1', 3, 3);
        $first = $this->publish('story-1');
        $this->voteOnLevel($first->id->value, 'lvl-1-1', 50, 9);

        $story = $this->stories()->get(new OwnerId($this->authorId), new StoryId('story-1'));
        $level = new Level(
            new LevelId('lvl-fresh'),
            'Fresh',
            new Dimensions(1600, 900),
            new Gravity(0, 1800),
            3,
            [$this->entity('e1', 'terrain')],
        );
        $story->addLevel(new ChapterId('ch-1'), $level, new MapNode(new LevelId('lvl-fresh'), 80, 80));
        $this->stories()->save($story);

        $second = $this->publish('story-1');

        self::assertCount(0, app(VoteRepository::class)->forLevel($second->id, 'lvl-fresh'));
    }

    // --- helpers ---------------------------------------------------------

    private function stories(): StoryRepository
    {
        return app(StoryRepository::class);
    }

    private function publish(string $storyId): \Wob\Publishing\Domain\Model\Release
    {
        return app(PublishReleaseHandler::class)(new PublishRelease($this->authorId, $storyId));
    }

    private function publishAndClear(string $storyId): \Wob\Publishing\Domain\Model\Release
    {
        $release = $this->publish($storyId);
        $release->clearedByAuthor(now()->toDateTimeImmutable());
        app(ReleaseRepository::class)->save($release);

        return $release;
    }

    private function editLevel(string $storyId, string $levelId, string $newName): void
    {
        $story = $this->stories()->get(new OwnerId($this->authorId), new StoryId($storyId));
        $story->level(new LevelId($levelId))->rename($newName);
        $this->stories()->save($story);
    }

    /** Enough players at 90%+ of their route, each rating every level the same. */
    private function giveQuorum(\Wob\Publishing\Domain\ValueObject\ReleaseId $releaseId, int $players, int $rating): void
    {
        $completions = app(ReleaseCompletionRepository::class);
        $votes = app(VoteRepository::class);
        $release = app(ReleaseRepository::class)->get($releaseId);
        $levelIds = $release->content->levelIds();

        DB::table('votes')->where('release_id', $releaseId->value)->delete();
        DB::table('release_completions')->where('release_id', $releaseId->value)->delete();

        $batch = [];

        for ($i = 0; $i < $players; $i++) {
            $playerId = $this->makeUser("player{$i}@example.com");
            $completions->record($releaseId, $playerId, new RouteCompletion(9, 9));

            foreach ($levelIds as $levelId) {
                $batch[] = new Vote($releaseId, $levelId, $playerId, new Rating($rating), now()->toDateTimeImmutable());
            }
        }

        $votes->saveAll($batch);
    }

    private function voteOnLevel(string $releaseId, string $levelId, int $count, int $rating): void
    {
        $votes = [];
        $release = new \Wob\Publishing\Domain\ValueObject\ReleaseId($releaseId);

        for ($i = 0; $i < $count; $i++) {
            $votes[] = new Vote(
                $release,
                $levelId,
                $this->makeUser("voter{$i}@example.com"),
                new Rating($rating),
                now()->toDateTimeImmutable(),
            );
        }

        app(VoteRepository::class)->saveAll($votes);
    }

    private function makeUser(string $email): string
    {
        $existing = DB::table('users')->where('email', $email)->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = Uuid::uuid4()->toString();
        DB::table('users')->insert([
            'id' => $id,
            'google_sub' => 'sub-' . md5($email),
            'email' => $email,
            'display_name' => 'Player',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function authorStory(string $storyId, int $chapters, int $levelsEach): void
    {
        $chapterList = [];
        $levelList = [];

        for ($c = 1; $c <= $chapters; $c++) {
            $nodes = [];

            for ($l = 1; $l <= $levelsEach; $l++) {
                $levelId = new LevelId("lvl-{$c}-{$l}");
                $levelList[] = new Level(
                    $levelId,
                    "Level {$c}-{$l}",
                    new Dimensions(1600, 900),
                    new Gravity(0, 1800),
                    3,
                    [$this->entity("e{$c}{$l}a", 'terrain'), $this->entity("e{$c}{$l}b", 'terrain')],
                );
                $nodes[] = new MapNode($levelId, 10.0 * $l, 50.0);
            }

            $chapterList[] = new Chapter(new ChapterId("ch-{$c}"), "Chapter {$c}", '#123', $nodes);
        }

        $this->stories()->save(new Story(
            new StoryId($storyId),
            new OwnerId($this->authorId),
            'A story',
            '#000',
            $chapterList,
            $levelList,
        ));
    }

    private function entity(string $id, string $type): EntityPlacement
    {
        $data = new \stdClass();
        $data->points = [[0, 780], [400, 780], [800, 700]];
        $data->fill = '#2a3326';

        return new EntityPlacement($id, $type, $data);
    }
}
