<?php

declare(strict_types=1);

namespace Wob\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Wob\Identity\Infrastructure\Laravel\Auth\SignedInUser;
use Wob\Library\Domain\Model\Asset;
use Wob\Library\Domain\Model\Chapter;
use Wob\Library\Domain\Model\Level;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\Repository\AssetRepository;
use Wob\Library\Domain\Repository\StoryRepository;
use Wob\Library\Domain\ValueObject\AssetId;
use Wob\Library\Domain\ValueObject\CanvasRect;
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
use Wob\Publishing\Domain\Repository\ReleaseRepository;
use Wob\Tests\TestCase;

/**
 * Чужую историю играют с её ассетами, а не без них.
 *
 * Уровень не хранит, как выглядит шар: он называет ассет по имени
 * (`asset: "wog-ball-pilot"`), а выглядит шар так, как сказано на полке. Полка
 * же принадлежит автору — и это правильно, она его и переиспользуется во всём,
 * что он делает.
 *
 * Отсюда дыра: выпущенную историю играет кто угодно, а разрешить её ассеты мог
 * только автор. Уровень у чужого игрока запускался пустым — шары были, а
 * выглядеть им нечем.
 *
 * Прогон по всем уровням этого не ловил и поймать не мог: он ходит тем же
 * пользователем, который импортировал, и для него полка на месте. Проверка,
 * которая не меняет человека, про чужого игрока не знает ничего.
 */
final class ForeignAssetsTest extends TestCase
{
    use RefreshDatabase;

    private string $authorId = '';
    private string $playerId = '';
    private string $storyId = 'st-assets';

    protected function setUp(): void
    {
        parent::setUp();

        $this->authorId = $this->makeUser('author@example.com');
        $this->playerId = $this->makeUser('player@example.com');
        $this->storyWithABallAsset();
    }

    public function testTheAuthorSeesTheirOwnShelf(): void
    {
        $this->actingAs(new SignedInUser($this->authorId));

        $this->getJson('/api/assets')
            ->assertOk()
            ->assertJsonPath('assets.0.id', 'ball-1');
    }

    /** Полка автора и есть авторская: чужому она не показывается целиком. */
    public function testAnotherPlayerDoesNotGetTheAuthorsWholeShelf(): void
    {
        $this->actingAs(new SignedInUser($this->playerId));

        $this->getJson('/api/assets')
            ->assertOk()
            ->assertJsonPath('assets', []);
    }

    /**
     * А вот ассеты истории, в которую он играет, достаться ему обязаны.
     *
     * Иначе уровень запускается без них: шары на месте, а выглядеть им нечем.
     */
    public function testAnotherPlayerGetsTheAssetsTheContentNames(): void
    {
        $this->publish();
        $this->actingAs(new SignedInUser($this->playerId));

        $this->getJson("/api/catalog/{$this->storyId}/levels/lv-1")
            ->assertOk()
            ->assertJsonPath('assets.0.id', 'ball-1')
            // Не имя одно: игроку нужны сами части, иначе шар остаётся пустым.
            ->assertJsonCount(1, 'assets.0.entities')
            // И сам уровень — с сущностями: в истории он ехал без них.
            ->assertJsonCount(1, 'level.entities');
    }

    /** Отданы ровно названные, а не всё, что есть у автора. */
    public function testOnlyTheAssetsTheContentNamesComeBack(): void
    {
        app(AssetRepository::class)->save(new Asset(
            new AssetId('ball-unused'),
            new OwnerId($this->authorId),
            'Шар, которым никто не пользуется',
            [new EntityPlacement('e2', 'game-ball', (object) ['r' => 15], null, null)],
        ));

        $this->publish();
        $this->actingAs(new SignedInUser($this->playerId));

        $got = $this->getJson("/api/catalog/{$this->storyId}/levels/lv-1")->assertOk()->json('assets');

        self::assertSame(['ball-1'], array_map(static fn (array $a): string => $a['id'], $got));
    }

    /**
     * Ассет вправе называть другой ассет, и приехать обязаны оба.
     *
     * Ради этого случая связывание и делает сервер. Клиент мог бы спрашивать по
     * кругу — получил, нашёл новые имена, спросил снова, — но тогда число
     * обращений задаёт чужое содержимое: по набору оригинала глубина доходит до
     * трёх (`undeletepill → fizz → spam`), а завтра у кого-то будет пять.
     */
    public function testAnAssetNamingAnotherAssetBringsItAlong(): void
    {
        $shelf = app(AssetRepository::class);
        $shelf->save(new Asset(
            new AssetId('ball-deep'),
            new OwnerId($this->authorId),
            'Самый нижний',
            [new EntityPlacement('e3', 'game-ball', (object) ['r' => 5], null, null)],
        ));
        // ball-1 → ball-mid → ball-deep
        $shelf->save(new Asset(
            new AssetId('ball-mid'),
            new OwnerId($this->authorId),
            'Средний',
            [new EntityPlacement('e2', 'game-ball', (object) [], null, 'ball-deep', 'e3')],
        ));
        $shelf->save(new Asset(
            new AssetId('ball-1'),
            new OwnerId($this->authorId),
            'Шар',
            [new EntityPlacement('e1', 'game-ball', (object) ['r' => 15], null, 'ball-mid', 'e2')],
        ));

        $this->publish();
        $this->actingAs(new SignedInUser($this->playerId));

        $got = $this->getJson("/api/catalog/{$this->storyId}/levels/lv-1")->assertOk()->json('assets');
        $ids = array_map(static fn (array $a): string => $a['id'], $got);

        sort($ids);
        self::assertSame(['ball-1', 'ball-deep', 'ball-mid'], $ids, 'все три этажа за один раз');
    }

    /**
     * Круг ссылок не вешает обход.
     *
     * Запрещать круги здесь нельзя: выпуск с таким ассетом уже существует, и
     * играть его надо. Значит обход обязан его пережить — и переживает он его
     * на сервере один раз, а не в каждом клиенте по-своему.
     */
    public function testAssetsPointingAtEachOtherDoNotLoopForever(): void
    {
        $shelf = app(AssetRepository::class);
        $shelf->save(new Asset(
            new AssetId('ball-b'),
            new OwnerId($this->authorId),
            'Б',
            [new EntityPlacement('e2', 'game-ball', (object) [], null, 'ball-1', 'e1')],
        ));
        $shelf->save(new Asset(
            new AssetId('ball-1'),
            new OwnerId($this->authorId),
            'Шар',
            [new EntityPlacement('e1', 'game-ball', (object) ['r' => 15], null, 'ball-b', 'e2')],
        ));

        $this->publish();
        $this->actingAs(new SignedInUser($this->playerId));

        $got = $this->getJson("/api/catalog/{$this->storyId}/levels/lv-1")->assertOk()->json('assets');
        $ids = array_map(static fn (array $a): string => $a['id'], $got);

        sort($ids);
        self::assertSame(['ball-1', 'ball-b'], $ids);
    }

    /**
     * История едет без сущностей, а уровень — с ними.
     *
     * Ради этого всё и затевалось. По набору оригинала пакет истории весит
     * 1858 КБ, из них 1526 — сущности уровней. Игрок тянул их перед картой
     * целиком, чтобы открыть один уровень.
     */
    public function testTheStoryTravelsWithoutEntitiesAndTheLevelWithThem(): void
    {
        $this->publish();
        $this->actingAs(new SignedInUser($this->playerId));

        $story = $this->getJson("/api/catalog/{$this->storyId}")->assertOk();

        // Уровень в истории есть — карте он нужен, — но пустой изнутри.
        $story->assertJsonPath('levels.0.id', 'lv-1');
        self::assertArrayNotHasKey('entities', $story->json('levels.0'));
        // И ассетов в истории больше нет: они уедут с уровнем.
        self::assertNull($story->json('assets'));

        // Зато штамп хеша при нём: по нему карта и считает, не трогая сущности.
        self::assertNotEmpty($story->json('levels.0.hash'));
    }

    /**
     * Гость не обходит обрезку через отдельную ручку.
     *
     * Гейт, к которому есть второй путь, — не гейт. Гостю полагается один
     * уровень, и спросить второй по имени он не должен: «нет такого» и «не
     * полагается» отвечают одинаково, иначе по ответам перебирается чужое
     * содержимое.
     */
    public function testAVisitorCannotFetchALevelTheGateWithheld(): void
    {
        $this->publish();
        // Гость: без входа вовсе.
        $this->getJson("/api/catalog/{$this->storyId}/levels/lv-hidden")
            ->assertStatus(404);
    }

    /**
     * Картинка главы и её место доезжают до игрока.
     *
     * Без них экран выбора главы у игрока пуст: у автора всё расставлено, а
     * играющий видит ничто. Нашлось это не проверкой, а попыткой посмотреть
     * глазами — снимок выпуска нёс у главы только id, title, image и nodes.
     *
     * Заморожено вместе с остальным нарочно: игрок смотрит ту расстановку, в
     * которую играет, а автор волен передвинуть главы сразу после выпуска.
     */
    public function testTheChaptersPictureAndPlaceReachThePlayer(): void
    {
        $story = app(StoryRepository::class)->get(
            new OwnerId($this->authorId),
            new StoryId($this->storyId),
        );
        $story->chapter(new ChapterId('ch-1'))->setIcon('art/island.png');
        $story->chapter(new ChapterId('ch-1'))->placeOnCanvas(new CanvasRect(120, -40, 200, 160));
        app(StoryRepository::class)->save($story);

        $this->publish();
        $this->actingAs(new SignedInUser($this->playerId));

        $this->getJson("/api/catalog/{$this->storyId}")
            ->assertOk()
            ->assertJsonPath('chapters.0.icon', 'art/island.png')
            ->assertJsonPath('chapters.0.canvas.x', 120)
            ->assertJsonPath('chapters.0.canvas.w', 200);
    }

    /**
     * Выпустить и отметить пройденным автором.
     *
     * Второе обязательно: выпуск открывается остальным только когда автор
     * прошёл в нём каждый уровень. Без этого чужой игрок получает 404, и
     * проверка про ассеты не дошла бы до ассетов вовсе.
     */
    private function publish(): void
    {
        $this->actingAs(new SignedInUser($this->authorId));

        $release = app(PublishReleaseHandler::class)(
            new PublishRelease($this->authorId, $this->storyId),
        );

        $release->clearedByAuthor(new DateTimeImmutable());
        app(ReleaseRepository::class)->save($release);
    }

    private function storyWithABallAsset(): void
    {
        $owner = new OwnerId($this->authorId);

        app(AssetRepository::class)->save(new Asset(
            new AssetId('ball-1'),
            $owner,
            'Шар',
            [new EntityPlacement('e1', 'game-ball', (object) ['r' => 15], null, null)],
        ));

        $level = new Level(
            new LevelId('lv-1'),
            'Уровень',
            new Dimensions(800, 600),
            new Gravity(0, 1800),
            1,
            // Сущность называет ассет по имени — вот эта ссылка и не разрешалась.
            [new EntityPlacement('b1', 'game-ball', (object) ['x' => 10, 'y' => 10], null, 'ball-1', 'e1')],
        );

        $chapter = new Chapter(
            new ChapterId('ch-1'),
            'Глава',
            '',
            [new MapNode(new NodeId('nd-1'), new LevelId('lv-1'), 50.0, 50.0)],
        );

        app(StoryRepository::class)->save(new Story(
            new StoryId($this->storyId),
            $owner,
            'История',
            '#000',
            [$chapter],
            [$level],
        ));
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
