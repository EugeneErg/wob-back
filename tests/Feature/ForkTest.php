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
use Wob\Publishing\Domain\Model\Release;
use Wob\Publishing\Domain\Repository\ForkOverrideRepository;
use Wob\Publishing\Domain\Repository\ReleaseRepository;
use Wob\Tests\TestCase;

/**
 * Forking someone else's story, and offering the changes back.
 *
 * The thing worth proving is that a fork costs what it should: touch one level
 * and one row exists, with everything else still answered by the base release.
 */
final class ForkTest extends TestCase
{
    use RefreshDatabase;

    private string $authorId;
    private string $contributorId;
    private Release $release;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authorId = $this->makeUser('author@example.com');
        $this->contributorId = $this->makeUser('contributor@example.com');

        $this->authorStory('story-1');
        $this->release = app(PublishReleaseHandler::class)(new PublishRelease($this->authorId, 'story-1'));
        $this->release->clearedByAuthor(now()->toDateTimeImmutable());
        app(ReleaseRepository::class)->save($this->release);

        $this->actingAs(new SignedInUser($this->contributorId));
    }

    /** Opening the editor creates nothing. The fork is born on the first change. */
    public function testNoForkExistsUntilSomethingIsChanged(): void
    {
        self::assertSame(1, DB::table('stories')->count());
        self::assertSame(0, DB::table('edit_sessions')->count());
        self::assertSame(0, DB::table('fork_overrides')->count());
    }

    public function testChangingALevelCopiesOnlyThatLevel(): void
    {
        $fork = $this->editLevel('lvl-1', goal: 7);

        // One row for the touched level, and nothing else. The chapter is
        // untouched because nothing in it moved: its nodes still name the same
        // ids, which resolve to the fork's version of lvl-1 and the base's of
        // the rest.
        self::assertSame(1, DB::table('fork_overrides')->count());
        self::assertSame('level', DB::table('fork_overrides')->value('kind'));
        self::assertSame('lvl-1', DB::table('fork_overrides')->value('public_id'));

        // And the fork story itself holds no chapters or levels of its own.
        $forkUuid = DB::table('stories')->where('public_id', $fork)->value('id');
        self::assertSame(0, DB::table('levels')->where('story_id', $forkUuid)->count());
        self::assertSame(0, DB::table('chapters')->where('story_id', $forkUuid)->count());
    }

    /** Everything untouched still reads from the base. */
    public function testUntouchedContentResolvesToTheBaseRelease(): void
    {
        $fork = $this->editLevel('lvl-1', goal: 7);

        $overlay = app(ForkOverrideRepository::class)
            ->overlayFor(new StoryId($fork), $this->release->content);

        self::assertSame(7, $overlay->level('lvl-1')->goal, 'the changed level is the fork\'s');
        self::assertSame(3, $overlay->level('lvl-2')->goal, 'the rest comes from the base');
        self::assertNotNull($overlay->chapter('ch-1'), 'so does the chapter nobody touched');

        // Flattened, the fork is a whole story again.
        $flat = $overlay->flatten();
        self::assertCount(3, $flat->levels);
        self::assertCount(1, $flat->chapters);
    }

    /**
     * Deletion needs a tombstone. Absence already means "not touched, read the
     * base", so an unmarked delete would resurrect on the next read.
     */
    public function testDeletingInAForkDoesNotResurrect(): void
    {
        $fork = $this->request('lvl-2', null);

        $overlay = app(ForkOverrideRepository::class)
            ->overlayFor(new StoryId($fork), $this->release->content);

        self::assertCount(2, $overlay->flatten()->levels);
        self::assertNull(DB::table('fork_overrides')->where('public_id', 'lvl-2')->value('content'));
    }

    public function testEditingTwiceResumesTheSameForkRatherThanStartingAnother(): void
    {
        $first = $this->editLevel('lvl-1', goal: 7);
        $second = $this->editLevel('lvl-2', goal: 9);

        self::assertSame($first, $second);
        self::assertSame(1, DB::table('edit_sessions')->count());
        self::assertSame(2, DB::table('fork_overrides')->count());
    }

    public function testAForkWithNoChangesCannotBeProposed(): void
    {
        // A fork can only exist once something changed, so this reaches the
        // guard by undoing the change.
        $fork = $this->editLevel('lvl-1', goal: 7);
        DB::table('fork_overrides')->delete();

        $this->postJson("/api/forks/{$fork}/propose", ['title' => 'Nothing'])->assertStatus(422);
    }

    public function testAProposalIsListedAgainstTheOriginalStory(): void
    {
        $fork = $this->editLevel('lvl-1', goal: 7);
        $this->postJson("/api/forks/{$fork}/propose", ['title' => 'Harder first level'])->assertStatus(201);

        $this->actingAs(new SignedInUser($this->authorId));

        $this->getJson('/api/stories/story-1/pull-requests')
            ->assertOk()
            ->assertJsonPath('pullRequests.0.title', 'Harder first level')
            ->assertJsonPath('pullRequests.0.state', 'open');
    }

    /**
     * The heart of it: accepting makes a NEW draft and leaves the author's own
     * work exactly where it was.
     */
    public function testAcceptingCreatesANewDraftAndLeavesTheOriginalAlone(): void
    {
        $fork = $this->editLevel('lvl-1', goal: 7);
        $pr = $this->postJson("/api/forks/{$fork}/propose", ['title' => 'Harder'])->json();

        // Meanwhile the author has been working on their own draft.
        $story = app(StoryRepository::class)->get(new OwnerId($this->authorId), new StoryId('story-1'));
        $story->level(new LevelId('lvl-3'))->rename('Work in progress');
        app(StoryRepository::class)->save($story);

        $this->actingAs(new SignedInUser($this->authorId));
        $decision = $this->postJson("/api/pull-requests/{$pr['id']}/decide", ['decision' => 'accept'])
            ->assertOk()
            ->json();

        $draftId = $decision['draftStoryId'];
        self::assertNotNull($draftId);
        self::assertNotSame('story-1', $draftId);

        // The new draft holds the proposed change, in full and self-contained:
        // the author will edit and publish from it, so it must not go on
        // reading half its content from somebody else's release.
        $draft = app(StoryRepository::class)->get(new OwnerId($this->authorId), new StoryId($draftId));
        self::assertSame(7, $draft->level(new LevelId('lvl-1'))->goal());
        self::assertCount(3, $draft->levels());
        self::assertCount(1, $draft->chapters());

        // The author's own draft is untouched, unfinished edit and all.
        $original = app(StoryRepository::class)->get(new OwnerId($this->authorId), new StoryId('story-1'));
        self::assertSame(3, $original->level(new LevelId('lvl-1'))->goal());
        self::assertSame('Work in progress', $original->level(new LevelId('lvl-3'))->name());
    }

    /** Accepting does not publish: what players are playing must not change under them. */
    public function testAcceptingDoesNotPublishAnything(): void
    {
        $fork = $this->editLevel('lvl-1', goal: 7);
        $pr = $this->postJson("/api/forks/{$fork}/propose", ['title' => 'Harder'])->json();

        $this->actingAs(new SignedInUser($this->authorId));
        $this->postJson("/api/pull-requests/{$pr['id']}/decide", ['decision' => 'accept'])->assertOk();

        self::assertSame(1, DB::table('releases')->count(), 'still only the release that existed');
    }

    public function testOnlyTheStoryOwnerMayDecide(): void
    {
        $fork = $this->editLevel('lvl-1', goal: 7);
        $pr = $this->postJson("/api/forks/{$fork}/propose", ['title' => 'Harder'])->json();

        // The contributor is still signed in, and it is not their story.
        $this->postJson("/api/pull-requests/{$pr['id']}/decide", ['decision' => 'accept'])->assertStatus(403);
    }

    /**
     * Withdrawing is kept apart from being rejected. "The author said no" and
     * "I changed my mind" are different history, and a contributor should not
     * carry a rejection they never received.
     */
    public function testAContributorCanWithdrawTheirOwnProposal(): void
    {
        $fork = $this->editLevel('lvl-1', goal: 7);
        $pr = $this->postJson("/api/forks/{$fork}/propose", ['title' => 'Never mind'])->json();

        $this->postJson("/api/pull-requests/{$pr['id']}/decide", ['decision' => 'withdraw'])->assertOk();

        $this->actingAs(new SignedInUser($this->authorId));
        $this->getJson('/api/stories/story-1/pull-requests')
            ->assertJsonPath('pullRequests.0.state', 'withdrawn');
    }

    public function testADecidedProposalCannotBeDecidedAgain(): void
    {
        $fork = $this->editLevel('lvl-1', goal: 7);
        $pr = $this->postJson("/api/forks/{$fork}/propose", ['title' => 'Harder'])->json();

        $this->actingAs(new SignedInUser($this->authorId));
        $this->postJson("/api/pull-requests/{$pr['id']}/decide", ['decision' => 'accept'])->assertOk();
        $this->postJson("/api/pull-requests/{$pr['id']}/decide", ['decision' => 'reject'])->assertStatus(422);
    }

    // --- helpers ---------------------------------------------------------

    private function editLevel(string $levelId, int $goal): string
    {
        $level = $this->release->content->level($levelId);
        $changed = json_decode(json_encode($level, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $changed->goal = $goal;

        return $this->request($levelId, $changed);
    }

    private function request(string $levelId, ?object $content): string
    {
        return $this->postJson("/api/releases/{$this->release->id->value}/edit", [
            'kind' => 'level',
            'id' => $levelId,
            'content' => $content,
        ])->assertStatus(201)->json('forkStoryId');
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
            $nodes[] = new MapNode(new NodeId('nd-' . $id->value), $id, 10.0 * $l, 50.0);
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
