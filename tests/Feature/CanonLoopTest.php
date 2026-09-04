<?php

declare(strict_types=1);

namespace Wob\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Wob\Identity\Infrastructure\Laravel\Auth\SignedInUser;
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
use Wob\Library\Domain\ValueObject\NodeId;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Application\Command\PublishRelease;
use Wob\Publishing\Application\Handler\PublishReleaseHandler;
use Wob\Publishing\Application\Handler\ReevaluateCanonHandler;
use Wob\Publishing\Domain\Model\Release;
use Wob\Publishing\Domain\Repository\ReleaseCompletionRepository;
use Wob\Publishing\Domain\Repository\ReleaseRepository;
use Wob\Tests\TestCase;

/**
 * The loop from playing to canon, with nothing stubbed in the middle.
 *
 * Every piece of this existed and none of it was connected: nothing recorded
 * route completions, so the quorum was permanently zero and no story could be
 * crowned however popular; and nothing marked an author as having finished
 * their own release, so nothing was ever published to anybody either. Both
 * holes were invisible from the unit tests, because each part worked.
 */
final class CanonLoopTest extends TestCase
{
    use RefreshDatabase;

    private string $authorId;
    private Release $release;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authorId = $this->makeUser('author@example.com');
        $this->authorStory('story-1');
        $this->release = app(PublishReleaseHandler::class)(new PublishRelease($this->authorId, 'story-1'));
    }

    /** Publishing alone shows the story to nobody. */
    public function testAFreshReleaseIsNotOpenToAnyone(): void
    {
        self::assertFalse($this->release->isClearedByAuthor());

        $this->actingAs(new SignedInUser($this->makeUser('stranger@example.com')));
        $this->getJson('/api/catalog')->assertOk()->assertJsonPath('published', []);
    }

    /**
     * The author finishing their own release is what opens it. Nothing was
     * doing this, so nothing was ever published.
     */
    public function testTheAuthorFinishingTheirOwnStoryOpensIt(): void
    {
        $this->actingAs(new SignedInUser($this->authorId));
        $this->playThrough('story-1');

        $release = app(ReleaseRepository::class)->get($this->release->id);
        self::assertTrue($release->isClearedByAuthor());

        $this->actingAs(new SignedInUser($this->makeUser('stranger@example.com')));
        $this->getJson('/api/catalog')->assertOk()->assertJsonCount(1, 'published');
    }

    /** Route completions are derived from what the server knows, not reported. */
    public function testFinishingLevelsRecordsRouteProgress(): void
    {
        $this->actingAs(new SignedInUser($this->authorId));
        $slot = $this->startSlot('story-1');

        $this->finish('story-1', 'lvl-1-1', $slot);

        $completion = app(ReleaseCompletionRepository::class)
            ->forPlayer($this->release->id, $this->authorId);

        self::assertNotNull($completion);
        // The chapter the player entered counts in full; the ones they never
        // touched are not on their route. Nothing is finished as far as quorum
        // is concerned until they reach an ending.
        self::assertSame(3, $completion->levelsOnRoute);
        self::assertFalse($completion->countsTowardsQuorum());
    }

    /**
     * The hole this closes: finishing chapter one of a nine-chapter story used
     * to be 100% of that player's route, and therefore a playthrough. A hundred
     * and fifty people playing three levels could crown a story nobody had seen
     * the end of.
     */
    public function testFinishingOnlyTheFirstChapterIsNotAPlaythrough(): void
    {
        $this->actingAs(new SignedInUser($this->authorId));
        $slot = $this->startSlot('story-1');

        foreach (['lvl-1-1', 'lvl-1-2', 'lvl-1-3'] as $level) {
            $this->finish('story-1', $level, $slot);
        }

        $completion = app(ReleaseCompletionRepository::class)
            ->forPlayer($this->release->id, $this->authorId);

        self::assertFalse($completion->countsTowardsQuorum());
        self::assertSame(0, app(ReleaseCompletionRepository::class)->countAtQuorumThreshold($this->release->id));
    }

    public function testReachingTheEndIsWhatMakesItCount(): void
    {
        $this->actingAs(new SignedInUser($this->authorId));
        $this->playThrough('story-1');

        $completion = app(ReleaseCompletionRepository::class)
            ->forPlayer($this->release->id, $this->authorId);

        self::assertTrue($completion->countsTowardsQuorum());
        self::assertSame(1, app(ReleaseCompletionRepository::class)->countAtQuorumThreshold($this->release->id));
    }

    /**
     * And the whole way through: enough players finish and rate it, and the
     * crown moves. This was unreachable before — the quorum could not be
     * counted, so the answer was always no.
     */
    public function testAStoryCanActuallyReachCanon(): void
    {
        $this->actingAs(new SignedInUser($this->authorId));
        $this->playThrough('story-1');

        for ($i = 0; $i < 150; $i++) {
            $player = $this->makeUser("player{$i}@example.com");
            $this->actingAs(new SignedInUser($player));
            $this->playThrough('story-1', rate: 9);
        }

        self::assertGreaterThanOrEqual(
            150,
            app(ReleaseCompletionRepository::class)->countAtQuorumThreshold($this->release->id),
        );

        // The crown is already in place: it is granted the moment a vote takes
        // the release over the line, so an author watching their story cross it
        // sees it happen rather than finding out on the next cron run.
        self::assertSame(
            $this->release->id->value,
            DB::table('stories')->where('public_id', 'story-1')->value('canonical_release_id'),
        );

        // And asking again changes nothing. The crown only ever moves forward,
        // so re-evaluating an already-crowned release is a no-op rather than a
        // second coronation.
        self::assertFalse(app(ReevaluateCanonHandler::class)($this->release->id));

        // And now a signed-out visitor is offered it — one level of it.
        $this->app['auth']->guard('web')->logout();
        $body = $this->getJson('/api/catalog/story-1')->assertOk()->json();
        self::assertTrue($body['preview']);
        self::assertCount(1, $body['levels']);
    }

    /**
     * Progress belongs to the release, not to the author's draft.
     *
     * It was keyed on a row in the live levels table with a cascading delete,
     * so an author tidying up their draft silently wiped that level's progress
     * for everyone playing a frozen release it is still part of. The whole
     * promise of a release is that it does not change; progress against it was
     * hostage to edits somewhere else.
     */
    public function testProgressSurvivesTheAuthorEditingTheirDraft(): void
    {
        $player = $this->makeUser('player@example.com');
        $this->actingAs(new SignedInUser($player));
        $this->playThrough('story-1');

        $before = app(ReleaseCompletionRepository::class)->forPlayer($this->release->id, $player);
        self::assertSame(9, $before->levelsFinished);

        // The author removes a chapter from their draft — with it, in the old
        // schema, went the level rows and everyone's progress on them.
        $story = app(StoryRepository::class)->get(new OwnerId($this->authorId), new StoryId('story-1'));
        $story->removeChapter(new ChapterId('ch-1'));
        app(StoryRepository::class)->save($story);

        self::assertSame(0, DB::table('levels')->where('public_id', 'lvl-1-1')->count(), 'the draft row is gone');

        $after = app(ReleaseCompletionRepository::class)->forPlayer($this->release->id, $player);
        self::assertSame(9, $after->levelsFinished, 'but the run against the release is untouched');

        // And the player can still see what they finished.
        $this->actingAs(new SignedInUser($player));
        $done = $this->getJson('/api/progress')->assertOk()->json('completed');
        self::assertContains('lvl-1-1', $done);
    }

    // --- helpers ---------------------------------------------------------

    /**
     * A real playthrough: every chapter, to the end.
     *
     * It used to stop after chapter one and still count, which was the hole
     * this story is shaped to expose — three chapters, and only the last is an
     * ending.
     */
    private function playThrough(string $storyId, ?int $rate = null): void
    {
        $slot = $this->startSlot($storyId);

        foreach ($this->everyLevel() as $level) {
            $this->finish($storyId, $level, $slot);

            if ($rate !== null) {
                $this->postJson(
                    "/api/releases/{$this->release->id->value}/levels/{$level}/vote",
                    ['rating' => $rate],
                )->assertStatus(201);
            }
        }
    }

    /** @return list<string> */
    private function everyLevel(): array
    {
        $levels = [];

        for ($c = 1; $c <= 3; $c++) {
            for ($l = 1; $l <= 3; $l++) {
                $levels[] = "lvl-{$c}-{$l}";
            }
        }

        return $levels;
    }

    private function startSlot(string $storyId): string
    {
        return $this->postJson("/api/stories/{$storyId}/slots")->assertStatus(201)->json('id');
    }

    private function finish(string $storyId, string $levelId, string $slotId): void
    {
        $this->postJson('/api/progress/complete', [
            'storyId' => $storyId,
            'levelId' => $levelId,
            'slotId' => $slotId,
        ])->assertOk();
    }

    private function makeUser(string $email): string
    {
        $id = Uuid::uuid4()->toString();
        DB::table('users')->insert([
            'id' => $id,
            'google_sub' => 'sub-' . md5($email),
            'email' => $email,
            'display_name' => explode('@', $email)[0],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function authorStory(string $storyId): void
    {
        $chapters = [];
        $levels = [];

        // Three chapters of three, which is the floor for canon — and linked
        // one to the next, which is what makes it a story rather than three
        // unrelated maps. Only the last leads nowhere, so only the last is an
        // ending, and reaching it is what a playthrough means.
        for ($c = 1; $c <= 3; $c++) {
            $nodes = [];

            for ($l = 1; $l <= 3; $l++) {
                $id = new LevelId("lvl-{$c}-{$l}");
                $data = new \stdClass();
                $data->points = [[0, 780], [400, 780]];

                $levels[] = new Level(
                    $id,
                    "Level {$c}-{$l}",
                    new Dimensions(1600, 900),
                    new Gravity(0, 1800),
                    3,
                    [new EntityPlacement("e{$c}{$l}", 'terrain', $data)],
                );

                // The last point of each chapter but the last leads onward —
                // into the first point of the chapter after it, since links
                // join points now rather than naming a whole chapter.
                $next = ($l === 3 && $c < 3)
                    ? [new NodeId('nd-lvl-' . ($c + 1) . '-1')]
                    : [];
                $nodes[] = new MapNode(new NodeId('nd-' . $id->value), $id, 10.0 * $l, 50.0, $next);
            }

            $chapters[] = new Chapter(new ChapterId("ch-{$c}"), "Chapter {$c}", '#123', $nodes);
        }

        app(StoryRepository::class)->save(new Story(
            new StoryId($storyId),
            new OwnerId($this->authorId),
            'A story',
            '#000',
            $chapters,
            $levels,
        ));
    }
}
