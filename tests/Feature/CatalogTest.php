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
use Wob\Library\Domain\ValueObject\NodeId;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Application\Command\PublishRelease;
use Wob\Publishing\Application\Handler\PublishReleaseHandler;
use Wob\Publishing\Domain\Model\Release;
use Wob\Publishing\Domain\Repository\ReleaseRepository;
use Wob\Tests\TestCase;

/**
 * What a signed-out visitor is given.
 *
 * The frontend ships with no stories in it at all, so this is the only source
 * of anything playable. That makes these tests the gate: whatever comes back
 * here is exactly what a browser can reach, and anything withheld is
 * genuinely out of reach rather than merely hidden.
 */
final class CatalogTest extends TestCase
{
    use RefreshDatabase;

    private string $authorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authorId = $this->makeUser('author@example.com');
    }

    public function testAVisitorIsOfferedTheFirstCanonicalStoryOnly(): void
    {
        $this->crown('story-one', 'The first one');
        $this->crown('story-two', 'The second one');

        $body = $this->getJson('/api/catalog')->assertOk()->json();

        self::assertTrue($body['preview']);
        self::assertCount(1, $body['canon'], 'a taste is one story, not a shelf');
        self::assertSame('story-one', $body['canon'][0]['id']);
        self::assertSame([], $body['published'], 'uncrowned stories are not on offer at all');
    }

    /**
     * The heart of it: the levels beyond the first are not in the response.
     * A locked level that arrives in the payload is not locked — the client is
     * a browser, and everything it is handed it can read.
     */
    public function testAVisitorReceivesOneLevelAndNoMore(): void
    {
        $this->crown('story-one', 'The first one');

        $body = $this->getJson('/api/catalog/story-one')->assertOk()->json();

        self::assertTrue($body['preview']);
        self::assertCount(1, $body['levels']);
        self::assertSame('lvl-1-1', $body['levels'][0]['id']);

        // The map still shows what is ahead. The reason to sign in is far more
        // persuasive visible than described, so the node ids are there — what
        // is missing is anything to play.
        self::assertCount(1, $body['chapters']);
        self::assertCount(3, $body['chapters'][0]['nodes']);

        // Nothing but the first level has any content in the payload. The ids
        // of the others appear on the map, and that is all they are: names of
        // levels the browser has never been given.
        $shipped = array_column($body['levels'], 'id');
        self::assertSame(['lvl-1-1'], $shipped);

        foreach ($body['levels'] as $level) {
            self::assertArrayHasKey('entities', $level);
        }
    }

    public function testAVisitorCannotReachTheSecondCanonicalStoryByGuessingItsId(): void
    {
        $this->crown('story-one', 'The first one');
        $this->crown('story-two', 'The second one');

        $this->getJson('/api/catalog/story-two')->assertStatus(404);
    }

    public function testSigningInOpensTheWholeStory(): void
    {
        $this->crown('story-one', 'The first one');
        $this->crown('story-two', 'The second one');
        $this->signIn();

        $body = $this->getJson('/api/catalog/story-one')->assertOk()->json();

        self::assertFalse($body['preview']);
        self::assertCount(9, $body['levels']);
        self::assertCount(3, $body['chapters']);

        $catalog = $this->getJson('/api/catalog')->assertOk()->json();
        self::assertCount(2, $catalog['canon']);
        self::assertFalse($catalog['preview']);

        $this->getJson('/api/catalog/story-two')->assertOk();
    }

    /** An uncrowned story is playable once signed in — that is how it gathers votes. */
    public function testPublishedButUncrownedStoriesAppearOnlyForSignedInPlayers(): void
    {
        $this->publishOnly('story-new', 'Waiting for votes');

        $this->getJson('/api/catalog')->assertOk()->assertJsonPath('published', []);

        $this->signIn();

        $body = $this->getJson('/api/catalog')->assertOk()->json();
        self::assertCount(1, $body['published']);
        self::assertSame('story-new', $body['published'][0]['id']);
    }

    /** A story nobody has cleared is not on offer, however published it looks. */
    public function testAStoryItsAuthorHasNotFinishedIsNotListed(): void
    {
        $this->authorStory('story-raw', 'Unfinished');
        app(PublishReleaseHandler::class)(new PublishRelease($this->authorId, 'story-raw'));

        $this->signIn();

        $this->getJson('/api/catalog')->assertOk()->assertJsonPath('published', []);
    }

    public function testAnEmptyCanonOffersAVisitorNothingRatherThanFailing(): void
    {
        $body = $this->getJson('/api/catalog')->assertOk()->json();

        self::assertSame([], $body['canon']);
        self::assertTrue($body['preview']);
    }

    // --- helpers ---------------------------------------------------------

    private function crown(string $storyId, string $title): void
    {
        $release = $this->publishOnly($storyId, $title);

        DB::table('stories')->where('public_id', $storyId)->update([
            'canonical_release_id' => $release->id->value,
            'canonical_since' => now(),
        ]);

        // Ordering has to be unambiguous, and two rows stamped in the same
        // millisecond would make "the first canonical story" a coin toss.
        sleep(0);
        DB::table('stories')->where('public_id', $storyId)->update([
            'canonical_since' => now()->addSeconds(DB::table('stories')->whereNotNull('canonical_since')->count()),
        ]);
    }

    private function publishOnly(string $storyId, string $title): Release
    {
        $this->authorStory($storyId, $title);

        $release = app(PublishReleaseHandler::class)(new PublishRelease($this->authorId, $storyId));
        $release->clearedByAuthor(now()->toDateTimeImmutable());
        app(ReleaseRepository::class)->save($release);

        return $release;
    }

    private function signIn(): void
    {
        $this->actingAs(
            new \Wob\Identity\Infrastructure\Laravel\Auth\SignedInUser($this->makeUser('player@example.com')),
        );
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

    private function authorStory(string $storyId, string $title): void
    {
        $chapters = [];
        $levels = [];

        for ($c = 1; $c <= 3; $c++) {
            $nodes = [];

            for ($l = 1; $l <= 3; $l++) {
                $levelId = new LevelId("lvl-{$c}-{$l}");
                $levels[] = new Level(
                    $levelId,
                    "Level {$c}-{$l}",
                    new Dimensions(1600, 900),
                    new Gravity(0, 1800),
                    3,
                    [$this->entity("e{$c}{$l}")],
                );
                $nodes[] = new MapNode(new NodeId('nd-' . $levelId->value), $levelId, 10.0 * $l, 50.0);
            }

            $chapters[] = new Chapter(new ChapterId("ch-{$c}"), "Chapter {$c}", '#123', $nodes);
        }

        app(StoryRepository::class)->save(new Story(
            new StoryId($storyId),
            new OwnerId($this->authorId),
            $title,
            '#000',
            $chapters,
            $levels,
        ));
    }

    private function entity(string $id): EntityPlacement
    {
        $data = new \stdClass();
        $data->points = [[0, 780], [400, 780]];

        return new EntityPlacement($id, 'terrain', $data);
    }
}
