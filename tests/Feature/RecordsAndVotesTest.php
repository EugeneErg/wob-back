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
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Application\Command\PublishRelease;
use Wob\Publishing\Application\Handler\PublishReleaseHandler;
use Wob\Publishing\Domain\Model\Release;
use Wob\Publishing\Domain\Repository\ReleaseRepository;
use Wob\Tests\TestCase;

/**
 * Leaderboards and rating, end to end.
 *
 * These two are one test file because they are one loop: you play a level, the
 * run proves you played it, and having played it is what earns the right to
 * say what you thought of it.
 */
final class RecordsAndVotesTest extends TestCase
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
        $this->release->clearedByAuthor(now()->toDateTimeImmutable());
        app(ReleaseRepository::class)->save($this->release);

        $this->actingAs(new SignedInUser($this->authorId));
    }

    public function testARunIsRecordedAndAppearsOnTheBoard(): void
    {
        $this->submit(ticks: 900);
        $this->verifyEverything();

        $body = $this->board();

        self::assertCount(1, $body['board']);
        self::assertSame(1, $body['board'][0]['place']);
        self::assertSame(900, $body['board'][0]['ticks']);
        self::assertSame(900, $body['personalBest']['ticks']);
    }

    /**
     * Nothing is verified yet — the replay worker does not exist — and the board
     * says so rather than presenting a claim as a checked fact.
     */
    public function testRunsArriveUnverifiedAndAdmitIt(): void
    {
        $this->submit(ticks: 900);

        self::assertFalse($this->board()['board'][0]['verified']);
    }

    public function testTheBoardIsSortedByTime(): void
    {
        $this->submit(ticks: 900);

        $this->actingAs(new SignedInUser($this->makeUser('fast@example.com')));
        $this->submit(ticks: 600);

        $this->actingAs(new SignedInUser($this->makeUser('slow@example.com')));
        $this->submit(ticks: 1500);

        $this->verifyEverything();

        self::assertSame([600, 900, 1500], array_column($this->board()['board'], 'ticks'));
        self::assertSame([1, 2, 3], array_column($this->board()['board'], 'place'));
    }

    /**
     * An unchecked time is a claim, and a claim does not hold first place in
     * public. Verification runs out of band, so between submitting and being
     * checked there is a window — and a fabricated time at the top of a board
     * people watch is not something to leave open for a cron interval.
     */
    public function testAnUncheckedTimeIsNotShownToOthers(): void
    {
        $this->submit(ticks: 900);
        $this->verifyEverything();

        $cheat = $this->makeUser('cheat@example.com');
        $this->actingAs(new SignedInUser($cheat));
        $this->submit(ticks: 1);

        // The runner sees their own pending time: from their side the run
        // happened, and hiding it would read as the game having lost it.
        self::assertSame([1, 900], array_column($this->board()['board'], 'ticks'));

        // Everybody else sees only what has been checked.
        $this->actingAs(new SignedInUser($this->makeUser('bystander@example.com')));
        self::assertSame([900], array_column($this->board()['board'], 'ticks'));
    }

    /**
     * One entry per runner. A table where the same person holds the top five
     * places is a list of their attempts, not a ranking — and it pushes
     * everyone else off the page.
     */
    public function testOnlyAPlayersBestRunIsRanked(): void
    {
        $this->submit(ticks: 900);
        $this->submit(ticks: 700);
        $this->submit(ticks: 1200);
        $this->verifyEverything();

        $board = $this->board()['board'];

        self::assertCount(1, $board);
        self::assertSame(700, $board[0]['ticks']);
    }

    /** any% and 100% are different contests and never share a table. */
    public function testCategoriesAreSeparateBoards(): void
    {
        $this->submit(ticks: 900, category: 'any');
        $this->submit(ticks: 2000, category: 'hundred');
        $this->verifyEverything();

        self::assertSame([900], array_column($this->board(category: 'any')['board'], 'ticks'));
        self::assertSame([2000], array_column($this->board(category: 'hundred')['board'], 'ticks'));
    }

    /**
     * Physics is part of what makes two times comparable.
     *
     * A release pins the content; the rules version pins the solver. A change
     * to the solver alters what is achievable, so runs from two versions of the
     * physics belong on two boards — and old runs are replayed on the engine
     * they were set on rather than being declared forgeries by a change nobody
     * who set them had any part in.
     */
    public function testBoardsCanBeSplitByPhysicsVersion(): void
    {
        $this->submitWith(ticks: 900, rules: '1');

        $this->actingAs(new SignedInUser($this->makeUser('later@example.com')));
        $this->submitWith(ticks: 400, rules: '2');
        $this->verifyEverything();

        $onOne = $this->boardWith('1');
        self::assertSame([900], array_column($onOne['board'], 'ticks'));

        $onTwo = $this->boardWith('2');
        self::assertSame([400], array_column($onTwo['board'], 'ticks'));

        // Unsplit, the two sit together and the faster physics wins — which is
        // the whole argument for splitting them: 400 ticks on a changed solver
        // is not a better run than 900 on the old one, it is a different game.
        self::assertSame([400, 900], array_column($this->boardWith(null)['board'], 'ticks'));
    }

    /**
     * And within one physics version, a single runner still gets one row —
     * splitting boards must not accidentally undo that.
     */
    public function testSplittingDoesNotBreakOneRowPerRunner(): void
    {
        $this->submitWith(ticks: 900, rules: '1');
        $this->submitWith(ticks: 700, rules: '1');
        $this->verifyEverything();

        self::assertSame([700], array_column($this->boardWith('1')['board'], 'ticks'));
    }

    public function testARunAgainstALevelThatIsNotInTheReleaseIsRefused(): void
    {
        $this->postJson("/api/releases/{$this->release->id->value}/records", [
            'scope' => 'level',
            'target' => 'lvl-nonexistent',
            'category' => 'any',
            'ticks' => 500,
            'seed' => 1,
            'rulesVersion' => '1',
            'input' => [],
        ])->assertStatus(404);
    }

    // --- voting ----------------------------------------------------------

    /**
     * The gate on rating. An opinion about a puzzle from someone who never
     * solved it is not evidence about the puzzle.
     */
    public function testYouCannotRateALevelYouHaveNotFinished(): void
    {
        $this->vote('lvl-1', 9)->assertStatus(403);
    }

    public function testFinishingALevelEarnsTheRightToRateIt(): void
    {
        $this->submit(ticks: 900, target: 'lvl-1');

        $this->vote('lvl-1', 9)->assertStatus(201)->assertJsonPath('rating', 9);
    }

    /**
     * Most people finishing a level are not racing it. An earlier version
     * looked only at speedrun records, which quietly reserved the right to rate
     * a level for the people speedrunning it.
     */
    public function testFinishingALevelInASaveSlotAlsoEarnsIt(): void
    {
        $slot = $this->postJson('/api/stories/story-1/slots')->assertStatus(201)->json();

        $this->postJson('/api/progress/complete', [
            'storyId' => 'story-1',
            'levelId' => 'lvl-2',
            'slotId' => $slot['id'],
        ])->assertOk();

        $this->vote('lvl-2', 8)->assertStatus(201);
    }

    public function testChangingYourMindReplacesYourVoteRatherThanAddingOne(): void
    {
        $this->submit(ticks: 900, target: 'lvl-1');

        $this->vote('lvl-1', 4)->assertStatus(201);
        $this->vote('lvl-1', 9)->assertStatus(201);

        self::assertSame(1, DB::table('votes')->count());
        self::assertSame(9.0, (float) DB::table('votes')->value('rating'));
    }

    /**
     * The property that makes weighting better than discarding: it heals.
     *
     * An opinion formed on an older version of a level counts for less. Play
     * the new version, rate it again, and it counts fully — because you have
     * now actually seen the thing you are rating. Nobody administers this and
     * nobody has to ask for it.
     */
    public function testRatingAgainRestoresFullWeight(): void
    {
        $this->submit(ticks: 900, target: 'lvl-1');
        $this->vote('lvl-1', 8)->assertStatus(201);

        // Simulate an edit having faded this opinion across a release.
        DB::table('votes')->update(['weight' => 0.25, 'carried_over' => true]);
        self::assertEqualsWithDelta(8.0, $this->standing()['averageRating'], 0.001);

        // A second voter, at full weight, ought to dominate a faded one.
        $other = $this->makeUser('other@example.com');
        $this->actingAs(new SignedInUser($other));
        $this->submit(ticks: 800, target: 'lvl-1');
        $this->vote('lvl-1', 2)->assertStatus(201);

        // 8 at weight 0.25 against 2 at weight 1 — nearer 2 than the plain
        // average of 5 would be.
        self::assertLessThan(4.0, $this->standing()['averageRating']);

        // Now the first voter plays the new version and says so again.
        $this->actingAs(new SignedInUser($this->authorId));
        $this->vote('lvl-1', 8)->assertStatus(201);

        self::assertEqualsWithDelta(
            1.0,
            (float) DB::table('votes')->where('voter_id', $this->authorId)->value('weight'),
            0.001,
            'rating the current version restores the opinion in full',
        );

        self::assertEqualsWithDelta(5.0, $this->standing()['averageRating'], 0.001);
    }

    /** An author who is not there yet must be able to see the gap. */
    public function testTheStandingSpellsOutWhatIsStillMissing(): void
    {
        $this->submit(ticks: 900, target: 'lvl-1');
        $this->vote('lvl-1', 6);

        $body = $this->getJson("/api/releases/{$this->release->id->value}/standing")->assertOk()->json();

        self::assertFalse($body['qualifies']);
        self::assertSame(150, $body['quorum']);
        // Compared with a delta rather than identically: JSON does not carry
        // the difference between 6 and 6.0, and pinning it would be testing the
        // encoder rather than the average.
        self::assertEqualsWithDelta(6.0, $body['averageRating'], 0.001);

        $missing = implode(' ', $body['missing']);
        self::assertStringContainsString('150', $missing);
        self::assertStringContainsString('6.0', $missing);
    }

    // --- helpers ---------------------------------------------------------

    private function submit(int $ticks, string $target = 'lvl-1', string $category = 'any'): void
    {
        $this->postJson("/api/releases/{$this->release->id->value}/records", [
            'scope' => 'level',
            'target' => $target,
            'category' => $category,
            'ticks' => $ticks,
            'seed' => 12345,
            'rulesVersion' => '1',
            'input' => [1, 2, 3, 4],
        ])->assertStatus(201);
    }

    /** @return array<string, mixed> */
    private function board(string $category = 'any', string $target = 'lvl-1'): array
    {
        return $this->getJson(
            "/api/releases/{$this->release->id->value}/records?scope=level&target={$target}&category={$category}",
        )->assertOk()->json();
    }

    private function vote(string $levelId, int $rating)
    {
        return $this->postJson(
            "/api/releases/{$this->release->id->value}/levels/{$levelId}/vote",
            ['rating' => $rating],
        );
    }

    /** @return array<string, mixed> */
    private function standing(): array
    {
        return $this->getJson("/api/releases/{$this->release->id->value}/standing")->assertOk()->json();
    }

    /**
     * Stand in for the replay worker.
     *
     * Verification is a separate service that replays runs through the real
     * solver; these tests are about what the board does with the answer, not
     * about the answer itself.
     */
    private function verifyEverything(): void
    {
        DB::table('speedrun_records')->whereNull('verified_at')->update(['verified_at' => now()]);
    }

    private function submitWith(int $ticks, string $rules): void
    {
        $this->postJson("/api/releases/{$this->release->id->value}/records", [
            'scope' => 'level',
            'target' => 'lvl-1',
            'category' => 'any',
            'ticks' => $ticks,
            'seed' => 1,
            'rulesVersion' => $rules,
            'input' => [],
        ])->assertStatus(201);
    }

    /** @return array<string, mixed> */
    private function boardWith(?string $rules): array
    {
        $query = 'scope=level&target=lvl-1&category=any' . ($rules === null ? '' : "&rules={$rules}");

        return $this->getJson("/api/releases/{$this->release->id->value}/records?{$query}")->assertOk()->json();
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
        $levels = [];
        $nodes = [];

        for ($l = 1; $l <= 3; $l++) {
            $id = new LevelId("lvl-{$l}");
            $data = new \stdClass();
            $data->points = [[0, 780], [400, 780]];

            $levels[] = new Level(
                $id,
                "Level {$l}",
                new Dimensions(1600, 900),
                new Gravity(0, 1800),
                3,
                [new EntityPlacement("e{$l}", 'terrain', $data)],
            );
            $nodes[] = new MapNode($id, 10.0 * $l, 50.0);
        }

        app(StoryRepository::class)->save(new Story(
            new StoryId($storyId),
            new OwnerId($this->authorId),
            'A story',
            '#000',
            [new Chapter(new ChapterId('ch-1'), 'Chapter', '#123', $nodes)],
            $levels,
        ));
    }
}
