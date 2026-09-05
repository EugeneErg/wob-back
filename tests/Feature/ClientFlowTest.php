<?php

declare(strict_types=1);

namespace Wob\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Wob\Identity\Infrastructure\Laravel\Auth\SignedInUser;
use Wob\Tests\TestCase;

/**
 * Весь путь автора телами, которые шлёт настоящий клиент.
 *
 * Написано после того, как создание уровня отвечало «The version field is
 * required»: версию сняли с клиента, из команд и из обработчиков, а правило в
 * validate() осталось. Ни один тест этого не заметил, и вот почему — все они
 * составляли тела сами и по привычке клали version внутрь. Лишние поля Laravel
 * молча игнорирует, так что проверки проходили на сломанном приложении.
 *
 * Здесь тела ровно такие, как в src/core/making.js и src/core/authoring.js:
 * ни одного лишнего ключа. Если сервер снова потребует поле, которого клиент не
 * шлёт, падать будет здесь, а не у автора в браузере.
 *
 * Правило простое: ничего не добавлять в эти запросы «для надёжности». Лишний
 * ключ здесь — это ровно та слепота, из-за которой тест и понадобился.
 */
final class ClientFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $id = (string) Uuid::uuid4();
        DB::table('users')->insert([
            'id' => $id,
            'google_sub' => 'flow',
            'email' => 'flow@example.com',
            'display_name' => 'Author',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs(new SignedInUser($id));
    }

    public function testAnAuthorCanGoFromNothingToAPlayableRelease(): void
    {
        // 1. Новая история. makeStory()
        $story = $this->postJson('/api/stories', [
            'title' => 'Моя история',
            'cover' => 'linear-gradient(140deg,#2b4a5c,#16242b)',
            'intro' => '',
            'chapter' => ['title' => 'Chapter 1', 'image' => 'linear-gradient(160deg,#1d3040,#0f1a20)'],
        ])->assertStatus(201)->json();

        $storyId = $story['id'];
        $chapterOne = $story['chapterId'];

        // 2. Вторая глава. makeChapter()
        $chapterTwo = $this->postJson("/api/stories/{$storyId}/chapters", [
            'title' => 'Chapter 2',
            'image' => 'linear-gradient(160deg,#1d3040,#0f1a20)',
        ])->assertStatus(201)->json('id');

        // 3. Уровень на карте. makeLevel()
        $first = $this->postJson("/api/stories/{$storyId}/levels", [
            'chapterId' => $chapterOne,
            'name' => 'Начало',
            'x' => 20,
            'y' => 50,
        ])->assertStatus(201)->json();

        // 4. Уровень без карты, кнопкой «+». makeSpareLevel()
        $spare = $this->postJson("/api/stories/{$storyId}/levels", [
            'chapterId' => null,
            'name' => 'Запасной',
        ])->assertStatus(201)->json();

        // 5. Тот же уровень ставится точкой на вторую карту. makePoint()
        $second = $this->postJson("/api/stories/{$storyId}/points", [
            'chapterId' => $chapterTwo,
            'levelId' => $spare['id'],
            'x' => 40,
            'y' => 60,
        ])->assertStatus(201)->json();

        // 6. Точку подвинули мышью. moveNode()
        $this->patchJson("/api/stories/{$storyId}/chapters/{$chapterOne}/nodes/{$first['nodeId']}", [
            'x' => 35,
            'y' => 45,
        ])->assertOk();

        // 7. Точку подписали. describeNode()
        $this->patchJson("/api/stories/{$storyId}/chapters/{$chapterOne}/nodes/{$first['nodeId']}", [
            'name' => 'Первое место',
            'image' => '#123',
            'outro' => '',
        ])->assertOk();

        // 8. Главу переименовали. describeChapter()
        $this->patchJson("/api/stories/{$storyId}/chapters/{$chapterOne}", [
            'title' => 'Начало пути',
            'image' => '#456',
        ])->assertOk();

        // 9. Связали две точки. linkNodes()
        $this->postJson("/api/stories/{$storyId}/links", [
            'from' => $first['nodeId'],
            'to' => $second['nodeId'],
        ])->assertStatus(201);

        // 10. Содержимое уровня из редактора. saveLevel()
        foreach ([$first['id'], $spare['id']] as $levelId) {
            $this->putJson("/api/stories/{$storyId}/levels/{$levelId}", [
                'name' => 'Уровень',
                'width' => 1600,
                'height' => 900,
                'gravity' => ['x' => 0, 'y' => 1800],
                'goal' => 1,
                'entities' => [
                    ['id' => 'e1', 'type' => 'terrain', 'data' => ['x' => 0, 'y' => 0, 'w' => 400, 'h' => 20]],
                ],
                'hot' => [],
                'image' => '',
            ])->assertOk();
        }

        // 11. Начало истории — на точке. renameStory()
        $this->patchJson("/api/stories/{$storyId}", [
            'title' => 'Моя история',
            'cover' => 'linear-gradient(140deg,#2b4a5c,#16242b)',
            'intro' => '',
            'startNodeId' => $first['nodeId'],
        ])->assertOk();

        // 12. Выпуск. releaseStory()
        $release = $this->postJson("/api/stories/{$storyId}/publish")->assertSuccessful()->json();
        self::assertSame(1, $release['number']);

        // Выпущенное ещё никому не видно: автор его не прошёл.
        self::assertSame([], $this->getJson('/api/catalog')->json('published'));

        // 13. Автор проходит каждый уровень.
        $slot = $this->postJson("/api/stories/{$storyId}/slots", ['name' => 'Прогон'])
            ->assertSuccessful()->json('id');

        foreach ([$first['id'], $spare['id']] as $levelId) {
            $this->postJson('/api/progress/complete', [
                'storyId' => $storyId,
                'levelId' => $levelId,
                'slotId' => $slot,
            ])->assertSuccessful();
        }

        // 14. Теперь история на витрине и открывается.
        self::assertSame([$storyId], array_column($this->getJson('/api/catalog')->json('published'), 'id'));

        $play = $this->getJson("/api/catalog/{$storyId}")->assertOk()->json();

        self::assertNotNull($play['releaseId']);
        self::assertCount(2, $play['chapters']);
        self::assertSame('Начало пути', $play['chapters'][0]['title']);
    }
}
