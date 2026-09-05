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
use Wob\Tests\TestCase;

/**
 * Кто и когда увидит выпущенную историю.
 *
 * Правило одно и простое: релиз открывается остальным, когда автор прошёл в нём
 * каждый уровень. Не «свой маршрут» — этим меряют игрока, выбравшего дорогу, а
 * автор отвечает за всю историю, включая ветви, по которым сам не пошёл бы.
 *
 * Проверяется через настоящие запросы, а не вызовом обработчика: прохождение
 * засчитывает сервер по тому, что знает сам, и весь смысл правила в том, что
 * клиент не может объявить себя прошедшим.
 */
final class ReleaseVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private string $authorId;
    private string $storyId = 'st-vis';

    protected function setUp(): void
    {
        parent::setUp();

        $this->authorId = $this->makeUser('author@example.com');
    }

    /** Развилка: две ветви по два уровня, всего пять. */
    public function testAReleaseStaysHiddenUntilTheAuthorClearsEveryLevel(): void
    {
        $this->branchingStory();
        $this->actingAs(new SignedInUser($this->authorId));
        $this->publish();

        self::assertSame([], $this->catalogPublished(), 'до прохождения релиз никому не виден');

        // Автор проходит историю по одной ветви — свой маршрут целиком.
        foreach (['lvl-start', 'lvl-a1', 'lvl-a2'] as $levelId) {
            $this->finish($levelId);
        }

        self::assertSame(
            [],
            $this->catalogPublished(),
            'одной пройденной ветви мало: вторая уехала бы к людям непроверенной',
        );

        // Вторая ветвь.
        $this->finish('lvl-b1');
        self::assertSame([], $this->catalogPublished(), 'остался последний уровень');

        $this->finish('lvl-b2');

        self::assertSame(
            [$this->storyId],
            $this->catalogPublished(),
            'пройдено всё — релиз открыт остальным',
        );
    }

    /**
     * Уровень, не поставленный ни на одну карту, релиз не держит.
     *
     * Его нельзя открыть: до него нет ни одной точки. Требуй его прохождения —
     * и один забытый черновик запер бы историю навсегда, причём автору нечего
     * было бы нажать, чтобы это исправить.
     */
    public function testALevelNobodyPinnedToAMapDoesNotHoldTheReleaseBack(): void
    {
        $this->branchingStory(withSpareLevel: true);
        $this->actingAs(new SignedInUser($this->authorId));
        $this->publish();

        foreach (['lvl-start', 'lvl-a1', 'lvl-a2', 'lvl-b1', 'lvl-b2'] as $levelId) {
            $this->finish($levelId);
        }

        self::assertSame([$this->storyId], $this->catalogPublished());
    }

    /**
     * Выпущенное и пройденное — играется.
     *
     * Витрина показывала такие истории, а выдача содержимого искала корону
     * канона и без неё отвечала 404. Автор выпускал историю, видел её на
     * витрине и не мог открыть.
     */
    public function testAPublishedStoryCanActuallyBePlayed(): void
    {
        $this->branchingStory();
        $this->actingAs(new SignedInUser($this->authorId));
        $this->publish();

        foreach (['lvl-start', 'lvl-a1', 'lvl-a2', 'lvl-b1', 'lvl-b2'] as $levelId) {
            $this->finish($levelId);
        }

        // Смотрит другой игрок, не автор.
        $this->actingAs(new SignedInUser($this->makeUser('player@example.com')));

        $body = $this->getJson("/api/catalog/{$this->storyId}")->assertOk()->json();

        self::assertNotNull($body['releaseId']);
        self::assertSame(1, $body['version']);
        self::assertCount(3, $body['chapters']);
        self::assertFalse($body['preview']);
    }

    /** Невыпущенный черновик не играется никем, кроме как в редакторе. */
    public function testADraftIsNotOnOffer(): void
    {
        $this->branchingStory();
        $this->actingAs(new SignedInUser($this->makeUser('player@example.com')));

        $this->getJson("/api/catalog/{$this->storyId}")->assertStatus(404);
    }

    /** Второй релиз снова закрыт, пока автор не пройдёт и его. */
    public function testANewReleaseIsHiddenAgainUntilItsAuthorClearsIt(): void
    {
        $this->branchingStory();
        $this->actingAs(new SignedInUser($this->authorId));
        $this->publish();

        foreach (['lvl-start', 'lvl-a1', 'lvl-a2', 'lvl-b1', 'lvl-b2'] as $levelId) {
            $this->finish($levelId);
        }

        self::assertSame([$this->storyId], $this->catalogPublished());

        // Автор снова правит черновик и выпускает.
        $story = $this->stories()->get(new OwnerId($this->authorId), new StoryId($this->storyId));
        $story->level(new LevelId('lvl-b2'))->rename('Rebuilt');
        $this->stories()->save($story);
        $this->publish();

        // Игроки остаются на первом релизе: второй выпущен, но не пройден, и
        // пускать в него людей раньше автора — ровно то, что правило запрещает.
        self::assertSame(
            1,
            $this->getJson("/api/catalog/{$this->storyId}")->json('version'),
            'пока новый релиз не пройден автором, играется прежний',
        );

        self::assertSame(
            [$this->storyId],
            $this->catalogPublished(),
            'история с витрины не пропадает из-за непройденного нового релиза',
        );

        // Автор проходит и его — теперь виден второй.
        $this->slot = null;

        foreach (['lvl-start', 'lvl-a1', 'lvl-a2', 'lvl-b1', 'lvl-b2'] as $levelId) {
            $this->finish($levelId);
        }

        self::assertSame(2, $this->getJson("/api/catalog/{$this->storyId}")->json('version'));
    }

    /**
     * Начатое доигрывается на своей версии, а перейти можно, если есть куда.
     *
     * Прогон привязан к версии, на которой начат: иначе содержимое поменялось бы
     * под руками посреди истории. Но и застревать навсегда неверно — автор мог
     * выпустить продолжение ровно там, где игрок остановился.
     */
    public function testAPlayerMayCarryARunOntoANewerRelease(): void
    {
        $this->branchingStory();
        $this->actingAs(new SignedInUser($this->authorId));
        $this->publish();

        foreach (['lvl-start', 'lvl-a1', 'lvl-a2', 'lvl-b1', 'lvl-b2'] as $levelId) {
            $this->finish($levelId);
        }

        $playerId = $this->makeUser('runner@example.com');
        $this->actingAs(new SignedInUser($playerId));

        $slot = $this->postJson("/api/stories/{$this->storyId}/slots", ['name' => 'Прогон'])
            ->assertSuccessful()->json('id');

        // Игрок прошёл начало и остановился.
        $this->postJson('/api/progress/complete', [
            'storyId' => $this->storyId, 'levelId' => 'lvl-start', 'slotId' => $slot,
        ])->assertSuccessful();

        // Пока новой версии нет, предлагать нечего.
        $this->getJson("/api/slots/{$slot}/upgrade")
            ->assertOk()->assertJsonPath('available', false);

        // Автор выпускает вторую версию и проходит её.
        $this->actingAs(new SignedInUser($this->authorId));
        $story = $this->stories()->get(new OwnerId($this->authorId), new StoryId($this->storyId));
        $story->level(new LevelId('lvl-b2'))->rename('Переделанный');
        $this->stories()->save($story);
        $this->publish();
        $this->slot = null;

        foreach (['lvl-start', 'lvl-a1', 'lvl-a2', 'lvl-b1', 'lvl-b2'] as $levelId) {
            $this->finish($levelId);
        }

        // Уровень, на котором игрок остановился, во второй версии есть —
        // значит переход возможен.
        $this->actingAs(new SignedInUser($playerId));

        $offer = $this->getJson("/api/slots/{$slot}/upgrade")->assertOk()->json();

        self::assertTrue($offer['available'], $offer['reason'] ?? '');
        self::assertSame(2, $offer['version']);

        $this->postJson("/api/slots/{$slot}/upgrade")->assertOk();

        // И повторно предлагать уже нечего.
        $this->getJson("/api/slots/{$slot}/upgrade")
            ->assertOk()->assertJsonPath('available', false);
    }

    /** Если уровня, на котором остановились, в новой версии нет — перехода нет. */
    public function testARunStaysPutWhenItsLevelIsGoneFromTheNewRelease(): void
    {
        $this->branchingStory();
        $this->actingAs(new SignedInUser($this->authorId));
        $this->publish();

        foreach (['lvl-start', 'lvl-a1', 'lvl-a2', 'lvl-b1', 'lvl-b2'] as $levelId) {
            $this->finish($levelId);
        }

        $playerId = $this->makeUser('stuck@example.com');
        $this->actingAs(new SignedInUser($playerId));

        $slot = $this->postJson("/api/stories/{$this->storyId}/slots", ['name' => 'Прогон'])
            ->assertSuccessful()->json('id');

        $this->postJson('/api/progress/complete', [
            'storyId' => $this->storyId, 'levelId' => 'lvl-a2', 'slotId' => $slot,
        ])->assertSuccessful();

        // Автор выбрасывает эту ветвь целиком и выпускает вторую версию.
        $this->actingAs(new SignedInUser($this->authorId));
        $story = $this->stories()->get(new OwnerId($this->authorId), new StoryId($this->storyId));
        $story->removeChapter(new ChapterId('ch-a'));
        $this->stories()->save($story);
        $this->publish();
        $this->slot = null;

        foreach (['lvl-start', 'lvl-b1', 'lvl-b2'] as $levelId) {
            $this->finish($levelId);
        }

        $this->actingAs(new SignedInUser($playerId));

        $offer = $this->getJson("/api/slots/{$slot}/upgrade")->assertOk()->json();

        self::assertFalse($offer['available']);
        self::assertStringContainsString('нет уровня', $offer['reason']);
    }

    // ---- обвязка ------------------------------------------------------------

    /** @return list<string> */
    private function catalogPublished(): array
    {
        return array_column($this->getJson('/api/catalog')->json('published'), 'id');
    }

    private function publish(): void
    {
        app(PublishReleaseHandler::class)(new PublishRelease($this->authorId, $this->storyId));
    }

    private function finish(string $levelId): void
    {
        $this->postJson('/api/progress/complete', [
            'storyId' => $this->storyId,
            'levelId' => $levelId,
            'slotId' => $this->slotId(),
        ])->assertSuccessful();
    }

    private ?string $slot = null;

    private function slotId(): string
    {
        return $this->slot ??= $this->postJson("/api/stories/{$this->storyId}/slots", [
            'name' => 'Author run',
        ])->assertSuccessful()->json('id');
    }

    private function stories(): StoryRepository
    {
        return app(StoryRepository::class);
    }

    /**
     * Одна глава со стартом, две главы-ветви.
     *
     * Развилка нужна по существу: только на ней видно разницу между «прошёл
     * свой маршрут» и «прошёл всё».
     */
    private function branchingStory(bool $withSpareLevel = false): void
    {
        $levels = [];
        $node = static fn (string $levelId, array $next = []): MapNode => new MapNode(
            new NodeId('nd-' . $levelId),
            new LevelId($levelId),
            50.0,
            50.0,
            next: array_map(static fn (string $n): NodeId => new NodeId('nd-' . $n), $next),
        );

        foreach (['lvl-start', 'lvl-a1', 'lvl-a2', 'lvl-b1', 'lvl-b2'] as $id) {
            $levels[] = $this->level($id);
        }

        if ($withSpareLevel) {
            // Сделан и никуда не поставлен.
            $levels[] = $this->level('lvl-orphan');
        }

        $chapters = [
            new Chapter(new ChapterId('ch-start'), 'Start', '#123', [
                $node('lvl-start', ['lvl-a1', 'lvl-b1']),
            ]),
            new Chapter(new ChapterId('ch-a'), 'Branch A', '#123', [
                $node('lvl-a1', ['lvl-a2']),
                $node('lvl-a2'),
            ]),
            new Chapter(new ChapterId('ch-b'), 'Branch B', '#123', [
                $node('lvl-b1', ['lvl-b2']),
                $node('lvl-b2'),
            ]),
        ];

        $this->stories()->save(new Story(
            new StoryId($this->storyId),
            new OwnerId($this->authorId),
            'Branching story',
            '#000',
            $chapters,
            $levels,
        ));
    }

    private function level(string $id): Level
    {
        return new Level(
            new LevelId($id),
            $id,
            new Dimensions(1600, 900),
            new Gravity(0, 1800),
            1,
            [$this->entity($id . '-a'), $this->entity($id . '-b')],
        );
    }

    private function entity(string $id): EntityPlacement
    {
        $data = new \stdClass();
        $data->x = 0;
        $data->y = 0;
        $data->w = 100;
        $data->h = 20;

        return new EntityPlacement($id, 'terrain', $data);
    }

    private function makeUser(string $email): string
    {
        $id = (string) Uuid::uuid4();

        DB::table('users')->insert([
            'id' => $id,
            'google_sub' => 'sub-' . $email,
            'email' => $email,
            'display_name' => 'Someone',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
