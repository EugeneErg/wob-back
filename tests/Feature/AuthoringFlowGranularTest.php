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
        $this->assertDatabaseHas('stories', ['public_id' => $this->storyId]);

        $version = $this->addChapter('ch-2', $version);
        $version = $this->addLevel('lvl-1', $this->chapterId, $version);
        $version = $this->addLevel('lvl-2', $this->chapterId, $version);
        $version = $this->addLevel('lvl-3', $this->id('ch-2'), $version);

        // A level's contents are their own write, sent when the author stops
        // moving things rather than when they remember the button.
        $version = $this->saveLevel('lvl-1', $version, goal: 7);

        $story = $this->getJson("/api/stories/{$this->storyId}")->assertOk();
        $story->assertJsonCount(2, 'chapters');
        $story->assertJsonCount(3, 'levels');

        $saved = collect($story->json('levels'))->firstWhere('id', $this->id('lvl-1'));
        self::assertSame(7, $saved['goal']);

        $this->postJson("/api/stories/{$this->storyId}/publish")->assertStatus(201)->assertJsonPath('number', 1);
    }

    /**
     * Each write carries the version it was based on, so a second device cannot
     * quietly overwrite the first. The queue stops on a conflict rather than
     * continuing blind.
     */
    /**
     * Второе устройство не знает о первом — и это ничему не мешает.
     *
     * Здесь стояла обратная проверка: отставшая запись отвергалась с 409. От
     * этого отказались, и отказ стоит объяснить. Создание уровня ничего не
     * затирает — оно добавляет, — так что спорить тут не о чем в принципе. Два
     * автора, добавившие по уровню, получают два уровня; отказ не спасал ничего,
     * а работу ломал.
     */
    public function testTwoDevicesMayBothAddALevelWithoutKnowingOfEachOther(): void
    {
        $version = $this->createStory();
        $this->addLevel('lvl-1', $this->chapterId, $version);

        // Телефон всё ещё думает, что история такая, какой он её видел.
        $this->postJson("/api/stories/{$this->storyId}/levels", [
            'chapterId' => $this->chapterId,
            'name' => 'From the phone',
            'x' => 30,
            'y' => 50,
            'version' => $version,
        ])->assertStatus(201);

        $this->getJson("/api/stories/{$this->storyId}")->assertJsonCount(2, 'levels');
    }

    public function testDeletingALevelIsItsOwnWrite(): void
    {
        $version = $this->createStory();
        $version = $this->addLevel('lvl-1', $this->chapterId, $version);
        $version = $this->addLevel('lvl-2', $this->chapterId, $version);

        $this->deleteJson("/api/stories/{$this->storyId}/chapters/{$this->chapterId}/levels/{$this->id('lvl-1')}", ['version' => $version])
            ->assertOk();

        $this->getJson("/api/stories/{$this->storyId}")->assertJsonCount(1, 'levels');
    }

    /** Entity data goes up and comes back untouched, whatever type it claims to be. */
    public function testEntityDataSurvivesEachSave(): void
    {
        $version = $this->createStory();
        $version = $this->addLevel('lvl-1', $this->chapterId, $version);

        $this->putJson("/api/stories/{$this->storyId}/levels/{$this->id('lvl-1')}", [
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

        $level = collect($this->getJson("/api/stories/{$this->storyId}")->json('levels'))->firstWhere('id', $this->id('lvl-1'));

        self::assertSame('not-invented-yet', $level['entities'][0]['type']);
        self::assertTrue($level['entities'][0]['data']['nested']['deep']);
    }

    // --- helpers ---------------------------------------------------------

    /**
     * Помощники возвращают имена, а не задают их: id чеканит сервер, и узнать
     * их можно только из ответа.
     */
    private string $storyId = '';
    private string $chapterId = '';

    /** @var array<string, string> рабочее прозвище в тесте -> выданный id */
    private array $levelIds = [];

    private function createStory(): int
    {
        $made = $this->postJson('/api/stories', [
            'title' => 'My story',
            'cover' => '#000',
            'chapter' => ['title' => 'Chapter one', 'image' => '#123'],
        ])->assertStatus(201)->json();

        $this->storyId = $made['id'];
        $this->chapterId = $this->getJson("/api/stories/{$this->storyId}")->json('chapters.0.id');

        return $made['version'];
    }

    private function addChapter(string $nick, int $version): int
    {
        $made = $this->postJson("/api/stories/{$this->storyId}/chapters", [
            'title' => 'Another chapter',
            'image' => '#123',
            'version' => $version,
        ])->assertStatus(201)->json();

        $this->levelIds[$nick] = $made['id'];

        return $made['version'];
    }

    private function addLevel(string $nick, string $chapterId, int $version): int
    {
        $made = $this->postJson("/api/stories/{$this->storyId}/levels", [
            'chapterId' => $chapterId,
            'name' => 'A level',
            'x' => 30,
            'y' => 50,
            'version' => $version,
        ])->assertStatus(201)->json();

        $this->levelIds[$nick] = $made['id'];

        return $made['version'];
    }

    /** Выданный сервером id по тому прозвищу, под которым тест его завёл. */
    private function id(string $nick): string
    {
        return $this->levelIds[$nick] ?? $nick;
    }

    private function saveLevel(string $nick, int $version, int $goal): int
    {
        return $this->putJson("/api/stories/{$this->storyId}/levels/{$this->id($nick)}", [
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
