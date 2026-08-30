<?php

declare(strict_types=1);

namespace Wob\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Wob\Identity\Application\DTO\GoogleIdentity;
use Wob\Identity\Application\Exception\AuthenticationFailed;
use Wob\Identity\Application\Port\GoogleIdentityVerifier;
use Wob\Tests\TestCase;

/**
 * The whole slice, against a real Postgres: sign in, author a story, save a
 * level, finish it.
 *
 * Google is faked at the port, not at the HTTP client. Faking it lower down
 * would mean minting real RS256 tokens in a test, which proves the JWT library
 * works — something its own suite already covers — and proves nothing about
 * this application. What is worth testing here is what happens to a verified
 * identity, and the port is exactly where that begins.
 */
final class AuthoringFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(GoogleIdentityVerifier::class, static fn (): GoogleIdentityVerifier => new class () implements GoogleIdentityVerifier {
            public function verify(string $credential): GoogleIdentity
            {
                return match ($credential) {
                    'good-token' => new GoogleIdentity('sub-1', 'author@example.com', true, 'Author', 'https://example.com/a.png'),
                    'other-author' => new GoogleIdentity('sub-2', 'other@example.com', true, 'Other', null),
                    'unverified-email' => new GoogleIdentity('sub-3', 'nobody@example.com', false, 'Nobody', null),
                    default => throw AuthenticationFailed::because('Google sign-in could not be verified'),
                };
            }
        });
    }

    public function testSigningInCreatesTheAccountOnFirstVisitAndReusesItAfter(): void
    {
        $first = $this->postJson('/api/auth/google', ['credential' => 'good-token']);
        $first->assertOk()->assertJsonPath('user.email', 'author@example.com');

        $id = $first->json('user.id');

        $this->postJson('/api/auth/google', ['credential' => 'good-token'])
            ->assertOk()
            ->assertJsonPath('user.id', $id);

        $this->assertDatabaseCount('users', 1);
    }

    public function testAnUnverifiedEmailIsRefused(): void
    {
        $this->postJson('/api/auth/google', ['credential' => 'unverified-email'])->assertStatus(401);
        $this->assertDatabaseCount('users', 0);
    }

    public function testABadCredentialIsRefused(): void
    {
        $this->postJson('/api/auth/google', ['credential' => 'nonsense'])->assertStatus(401);
    }

    public function testTheLibraryNeedsASession(): void
    {
        $this->getJson('/api/library')->assertStatus(401);
    }

    public function testAuthoringAStoryFromNothing(): void
    {
        $this->signIn();

        $this->postJson('/api/stories', [
            'id' => 'story-1',
            'title' => 'First steps',
            'cover' => '#123',
            'chapter' => ['id' => 'ch-1', 'title' => 'Basics', 'image' => '#456'],
        ])->assertStatus(201)->assertJsonPath('version', 1);

        $this->postJson('/api/stories/story-1/levels', [
            'id' => 'lvl-1',
            'chapterId' => 'ch-1',
            'name' => 'Tower',
            'x' => 20,
            'y' => 60,
            'version' => 1,
        ])->assertStatus(201)->assertJsonPath('version', 2);

        // Entity data goes in untouched and comes back untouched — including an
        // entity type this backend has never heard of.
        $save = $this->putJson('/api/stories/story-1/levels/lvl-1', [
            'name' => 'Tower',
            'width' => 1600,
            'height' => 900,
            'gravity' => ['x' => 0, 'y' => 1800],
            'goal' => 5,
            'entities' => [
                ['id' => 't1', 'type' => 'terrain', 'data' => ['points' => [[0, 780], [1600, 780]], 'smoothness' => 0.35]],
                ['id' => 'x9', 'type' => 'trampoline', 'data' => ['bounce' => 3, 'nested' => ['deep' => true]]],
            ],
            'hot' => [],
            'version' => 2,
        ]);

        $save->assertOk()->assertJsonPath('version', 3);

        $story = $this->getJson('/api/stories/story-1')->assertOk();
        $story->assertJsonPath('levels.0.goal', 5);
        $story->assertJsonPath('levels.0.entities.1.type', 'trampoline');
        $story->assertJsonPath('levels.0.entities.1.data.nested.deep', true);

        // The fingerprint is computed by the server, never taken from the client.
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $story->json('levels.0.hash'));
    }

    /** The level fingerprint is what a recording resolves against, so it must be fetchable by hash alone. */
    public function testALevelCanBeFetchedByItsContentHash(): void
    {
        $this->signIn();
        $this->createStoryWithLevel();

        $hash = $this->getJson('/api/stories/story-1')->json('levels.0.hash');

        $this->getJson("/api/content/levels/{$hash}")
            ->assertOk()
            ->assertJsonPath('id', 'lvl-1');
    }

    public function testAStaleWriteIsRefusedInsteadOfOverwriting(): void
    {
        $this->signIn();
        $this->createStoryWithLevel();

        // Version 2 was consumed by creating the level; a second device still
        // believes it is on 1.
        $this->putJson('/api/stories/story-1/levels/lvl-1', [
            'name' => 'Renamed by the other tab',
            'width' => 1600,
            'height' => 900,
            'gravity' => ['x' => 0, 'y' => 1800],
            'goal' => 3,
            'entities' => [],
            'hot' => [],
            'version' => 1,
        ])->assertStatus(409)->assertJsonPath('error.code', 'conflict');

        $this->getJson('/api/stories/story-1')->assertJsonPath('levels.0.name', 'Tower');
    }

    public function testOneAuthorCannotTouchAnotherStory(): void
    {
        $this->signIn();
        $this->createStoryWithLevel();
        $this->postJson('/api/auth/logout')->assertOk();

        $this->postJson('/api/auth/google', ['credential' => 'other-author'])->assertOk();

        // Not visible at all. Story ids are minted by the editor and unique per
        // author, so another author's "story-1" is not a story this account is
        // forbidden from — it is not a story this account has. 404 rather than
        // 403 is also the smaller leak: 403 would confirm the id exists.
        $this->getJson('/api/library')->assertJsonCount(0, 'stories');
        $this->getJson('/api/stories/story-1')->assertStatus(404);
        $this->deleteJson('/api/stories/story-1')->assertStatus(404);

        // The original owner still has it, untouched.
        $this->postJson('/api/auth/logout');
        $this->postJson('/api/auth/google', ['credential' => 'good-token'])->assertOk();
        $this->getJson('/api/stories/story-1')->assertOk()->assertJsonPath('levels.0.name', 'Tower');
    }

    /**
     * Deleting a chapter takes the levels nobody else pins with it — the rule
     * lives in the aggregate, and this checks it survives the round trip through
     * HTTP and Postgres.
     */
    public function testDeletingAChapterDropsItsOrphanedLevels(): void
    {
        $this->signIn();
        $this->createStoryWithLevel();

        $version = $this->getJson('/api/stories/story-1')->json('version');

        $this->deleteJson('/api/stories/story-1/chapters/ch-1', ['version' => $version])->assertOk();

        $story = $this->getJson('/api/stories/story-1');
        $story->assertJsonCount(0, 'chapters');
        $story->assertJsonCount(0, 'levels');
    }

    public function testFinishingALevelIsRecordedAndIsIdempotent(): void
    {
        $this->signIn();
        $this->createStoryWithLevel();

        $this->postJson('/api/progress/complete', ['storyId' => 'story-1', 'levelId' => 'lvl-1'])
            ->assertOk()
            ->assertJsonPath('completions', 1);

        // The client marks a level done the moment the last ball reaches the
        // pipe, and that message can arrive twice.
        $this->postJson('/api/progress/complete', ['storyId' => 'story-1', 'levelId' => 'lvl-1'])
            ->assertOk()
            ->assertJsonPath('completions', 2);

        $this->getJson('/api/progress')->assertOk()->assertJsonPath('completed', ['lvl-1']);
        $this->assertDatabaseCount('level_completions', 1);
    }

    public function testAMalformedLevelIsRejectedWithoutBlamingTheServer(): void
    {
        $this->signIn();
        $this->createStoryWithLevel();

        $this->putJson('/api/stories/story-1/levels/lvl-1', [
            'name' => 'Tower',
            'width' => 1600,
            'height' => 900,
            'gravity' => ['x' => 0, 'y' => 1800],
            'goal' => 3,
            // Two entities sharing an id: the level graph would be ambiguous.
            'entities' => [
                ['id' => 'same', 'type' => 'terrain', 'data' => []],
                ['id' => 'same', 'type' => 'terrain', 'data' => []],
            ],
            'hot' => [],
            'version' => 2,
        ])->assertStatus(422)->assertJsonPath('error.code', 'invalid');
    }

    private function signIn(): void
    {
        $this->postJson('/api/auth/google', ['credential' => 'good-token'])->assertOk();
    }

    private function createStoryWithLevel(): void
    {
        $this->postJson('/api/stories', [
            'id' => 'story-1',
            'title' => 'First steps',
            'cover' => '#123',
            'chapter' => ['id' => 'ch-1', 'title' => 'Basics', 'image' => '#456'],
        ])->assertStatus(201);

        $this->postJson('/api/stories/story-1/levels', [
            'id' => 'lvl-1',
            'chapterId' => 'ch-1',
            'name' => 'Tower',
            'x' => 20,
            'y' => 60,
            'version' => 1,
        ])->assertStatus(201);
    }
}
