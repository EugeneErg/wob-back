<?php

declare(strict_types=1);

namespace Wob\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Wob\Identity\Infrastructure\Laravel\Auth\SignedInUser;
use Wob\Tests\TestCase;

/**
 * Building a story one element at a time, the way the editor now does it.
 *
 * The earlier design sent the whole story on a Save button, and the flaw was
 * not the shape of the API: everything before that press existed only in the
 * author's browser, so a closed tab took the evening with it. Each element is
 * written as it is made, which means the most a lost tab can cost is the last
 * thing touched.
 *
 * Every step here goes through HTTP, because the hole this replaces was
 * invisible to tests that built stories through the repository.
 */
final class AuthoringFlowGranularTest extends TestCase
{
    use RefreshDatabase;

    private string $authorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authorId = $this->makeUser('author@example.com');
        $this->actingAs(new SignedInUser($this->authorId));
    }

    public function testAStoryIsBuiltPieceByPieceAndCanBePublished(): void
    {
        // A story exists on the server from the moment it is created, with
        // nothing in it. That is the point: an empty story is the most an
        // interrupted session can lose.
        $version = $this->createStory();
        $this->assertDatabaseHas('stories', ['public_id' => 'story-1']);

        $version = $this->addChapter('ch-2', $version);
        $version = $this->addLevel('lvl-1', 'ch-1', $version);
        $version = $this->addLevel('lvl-2', 'ch-1', $version);
        $version = $this->addLevel('lvl-3', 'ch-2', $version);

        // A level's contents are their own write, sent when the author stops
        // moving things rather than when they remember the button.
        $version = $this->saveLevel('lvl-1', $version, goal: 7);

        $story = $this->getJson('/api/stories/story-1')->assertOk();
        $story->assertJsonCount(2, 'chapters');
        $story->assertJsonCount(3, 'levels');

        $saved = collect($story->json('levels'))->firstWhere('id', 'lvl-1');
        self::assertSame(7, $saved['goal']);

        $this->postJson('/api/stories/story-1/publish')->assertStatus(201)->assertJsonPath('number', 1);
    }

    /**
     * Each write carries the version it was based on, so a second device cannot
     * quietly overwrite the first. The queue stops on a conflict rather than
     * continuing blind.
     */
    public function testAStaleWriteIsRefused(): void
    {
        $version = $this->createStory();
        $this->addLevel('lvl-1', 'ch-1', $version);

        // The other device still believes it is on the earlier version.
        $this->postJson('/api/stories/story-1/levels', [
            'id' => 'lvl-2',
            'chapterId' => 'ch-1',
            'name' => 'From the phone',
            'x' => 30,
            'y' => 50,
            'version' => $version,
        ])->assertStatus(409);
    }

    public function testDeletingALevelIsItsOwnWrite(): void
    {
        $version = $this->createStory();
        $version = $this->addLevel('lvl-1', 'ch-1', $version);
        $version = $this->addLevel('lvl-2', 'ch-1', $version);

        $this->deleteJson('/api/stories/story-1/chapters/ch-1/levels/lvl-1', ['version' => $version])
            ->assertOk();

        $this->getJson('/api/stories/story-1')->assertJsonCount(1, 'levels');
    }

    /** Entity data goes up and comes back untouched, whatever type it claims to be. */
    public function testEntityDataSurvivesEachSave(): void
    {
        $version = $this->createStory();
        $version = $this->addLevel('lvl-1', 'ch-1', $version);

        $this->putJson('/api/stories/story-1/levels/lvl-1', [
            'name' => 'Tower',
            'width' => 1600,
            'height' => 900,
            'gravity' => ['x' => 0, 'y' => 1800],
            'goal' => 3,
            'entities' => [[
                'id' => 'weird',
                'type' => 'not-invented-yet',
                'data' => ['nested' => ['deep' => true]],
            ]],
            'hot' => [],
            'version' => $version,
        ])->assertOk();

        $level = collect($this->getJson('/api/stories/story-1')->json('levels'))->firstWhere('id', 'lvl-1');

        self::assertSame('not-invented-yet', $level['entities'][0]['type']);
        self::assertTrue($level['entities'][0]['data']['nested']['deep']);
    }

    // --- helpers ---------------------------------------------------------

    private function createStory(): int
    {
        return $this->postJson('/api/stories', [
            'id' => 'story-1',
            'title' => 'My story',
            'cover' => '#000',
            'chapter' => ['id' => 'ch-1', 'title' => 'Chapter one', 'image' => '#123'],
        ])->assertStatus(201)->json('version');
    }

    private function addChapter(string $id, int $version): int
    {
        return $this->postJson('/api/stories/story-1/chapters', [
            'id' => $id,
            'title' => 'Another chapter',
            'image' => '#123',
            'version' => $version,
        ])->assertStatus(201)->json('version');
    }

    private function addLevel(string $id, string $chapterId, int $version): int
    {
        return $this->postJson('/api/stories/story-1/levels', [
            'id' => $id,
            'chapterId' => $chapterId,
            'name' => 'A level',
            'x' => 30,
            'y' => 50,
            'version' => $version,
        ])->assertStatus(201)->json('version');
    }

    private function saveLevel(string $id, int $version, int $goal): int
    {
        return $this->putJson("/api/stories/story-1/levels/{$id}", [
            'name' => 'A level',
            'width' => 1600,
            'height' => 900,
            'gravity' => ['x' => 0, 'y' => 1800],
            'goal' => $goal,
            'entities' => [],
            'hot' => [],
            'version' => $version,
        ])->assertOk()->json('version');
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
}
