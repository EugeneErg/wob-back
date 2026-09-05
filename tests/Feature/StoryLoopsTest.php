<?php

declare(strict_types=1);

namespace Wob\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Wob\Identity\Infrastructure\Laravel\Auth\SignedInUser;
use Wob\Tests\TestCase;

/**
 * Связи между точками не должны замыкаться в кольцо.
 *
 * Кольцо не портит вид графа — оно запирает содержимое. Точка открывается,
 * когда пройден хоть один ведущий в неё родитель; у каждой точки кольца
 * родитель есть, и ни один не проходим. Всё кольцо и всё, что за ним, остаётся
 * недостижимым навсегда, при том что история спокойно сохраняется и отдаётся.
 *
 * Клиент спрашивает о том же перед тем, как провести линию. Здесь проверяется
 * то, что нельзя обойти мимо клиента.
 */
final class StoryLoopsTest extends TestCase
{
    use RefreshDatabase;

    private string $storyId;
    private string $chapterId;

    protected function setUp(): void
    {
        parent::setUp();

        $id = (string) Uuid::uuid4();
        DB::table('users')->insert([
            'id' => $id,
            'google_sub' => 'loops',
            'email' => 'loops@example.com',
            'display_name' => 'Author',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs(new SignedInUser($id));
    }

    public function testAMapThatLeadsBackOnItselfIsRefused(): void
    {
        [$version, $one, $two] = $this->storyOfTwoPoints();

        // Первая половина кольца законна: это обычная дорога вперёд.
        $version = $this->saveMap($version, [
            $this->node($one, $this->levelOf($one), [$two]),
            $this->node($two, $this->levelOf($two), []),
        ])->assertOk()->json('version');

        // А вот обратная связь замыкает круг.
        $this->saveMap($version, [
            $this->node($one, $this->levelOf($one), [$two]),
            $this->node($two, $this->levelOf($two), [$one]),
        ])->assertStatus(422)->assertJsonPath('error.code', 'invalid');

        // Отказ должен быть целым: карта осталась той, что была до попытки.
        $nodes = $this->getJson("/api/stories/{$this->storyId}")->json('chapters.0.nodes');
        $second = collect($nodes)->firstWhere('id', $two);

        self::assertSame([], $second['next'], 'отвергнутая карта не должна применяться наполовину');
    }

    /**
     * Кратчайшее кольцо — из точки в саму себя.
     *
     * Его ловит сама точка, и ловила задолго до этой проверки: здесь она
     * записана не как новое правило, а как граница, ниже которой проверка колец
     * начинаться уже не обязана.
     */
    public function testAPointCannotLeadToItself(): void
    {
        [$version, $one, $two] = $this->storyOfTwoPoints();

        // Обе точки на месте: единственное, что не так с этой картой, — петля
        // из точки в саму себя. Иначе отказ мог бы прийти за снятую с карты
        // точку и ничего не сказать про кольца.
        $this->saveMap($version, [
            $this->node($one, $this->levelOf($one), [$one]),
            $this->node($two, $this->levelOf($two), []),
        ])->assertStatus(422)->assertJsonPath('error.code', 'invalid');
    }

    /** Кольцо через три точки ловится так же, как через две. */
    public function testALongerLoopIsRefusedToo(): void
    {
        [$version, $one, $two] = $this->storyOfTwoPoints();
        $three = $this->addLevel($version, 'Third');
        $version = $three['version'];
        $threeId = $three['nodeId'];

        $this->saveMap($version, [
            $this->node($one, $this->levelOf($one), [$two]),
            $this->node($two, $this->levelOf($two), [$threeId]),
            $this->node($threeId, $this->levelOf($threeId), [$one]),
        ])->assertStatus(422);
    }

    /**
     * Слияние веток кольцом не является и остаётся законным.
     *
     * Две дороги, сходящиеся в одну точку, — это ровно то, что умеет правило
     * открытия: точка откроется по любому из пройденных родителей. Запретить
     * это заодно с кольцами значило бы урезать содержимое ради проверки.
     */
    public function testTwoRoadsMayMeetInOnePoint(): void
    {
        [$version, $one, $two] = $this->storyOfTwoPoints();
        $three = $this->addLevel($version, 'Third');

        $this->saveMap($three['version'], [
            $this->node($one, $this->levelOf($one), [$three['nodeId']]),
            $this->node($two, $this->levelOf($two), [$three['nodeId']]),
            $this->node($three['nodeId'], $this->levelOf($three['nodeId']), []),
        ])->assertOk();
    }

    /** Начать историю можно с любой точки, в том числе с той, куда что-то ведёт. */
    public function testTheStoryMayStartOnAPointSomethingLeadsInto(): void
    {
        [$version, $one, $two] = $this->storyOfTwoPoints();

        $version = $this->saveMap($version, [
            $this->node($one, $this->levelOf($one), [$two]),
            $this->node($two, $this->levelOf($two), []),
        ])->json('version');

        $this->patchJson("/api/stories/{$this->storyId}", [
            'startNodeId' => $two,
            'version' => $version,
        ])->assertOk();

        self::assertSame($two, $this->getJson("/api/stories/{$this->storyId}")->json('startNodeId'));
    }

    /**
     * Выйдя из главы, путь не возвращается в неё: ch1 → ch2 → ch1.
     *
     * Кольцом это не является — по точкам дорога идёт строго вперёд и нигде не
     * смыкается, — поэтому проверка колец такое пропускает и нужна отдельная.
     */
    public function testAPathMayNotComeBackIntoAChapterItLeft(): void
    {
        [$version, $one, $two] = $this->storyOfTwoPoints();

        $second = $this->addChapter($version, 'Two');
        $far = $this->addLevelTo($second['version'], $second['id'], 'Far');

        // Первая глава уходит во вторую — законно.
        $version = $this->saveMap($far['version'], [
            $this->node($one, $this->levelOf($one), [$far['nodeId']]),
            $this->node($two, $this->levelOf($two), []),
        ])->assertOk()->json('version');

        // А вторая ведёт обратно в первую — вот это уже второй заход.
        $this->saveMapOf($second['id'], $version, [
            $this->node($far['nodeId'], $this->levelOf($far['nodeId']), [$two]),
        ])->assertStatus(422)->assertJsonPath('error.code', 'invalid');
    }

    /** Внутри одной главы ходить можно сколько угодно: запрещён только возврат. */
    public function testMovingAroundInsideOneChapterIsFine(): void
    {
        [$version, $one, $two] = $this->storyOfTwoPoints();
        $three = $this->addLevel($version, 'Third');

        $this->saveMap($three['version'], [
            $this->node($one, $this->levelOf($one), [$two]),
            $this->node($two, $this->levelOf($two), [$three['nodeId']]),
            $this->node($three['nodeId'], $this->levelOf($three['nodeId']), []),
        ])->assertOk();
    }

    /** Пройти насквозь через главу и уйти дальше — законный путь, а не возврат. */
    public function testAPathMayPassThroughChaptersOneAfterAnother(): void
    {
        [$version, $one, $two] = $this->storyOfTwoPoints();

        $second = $this->addChapter($version, 'Two');
        $mid = $this->addLevelTo($second['version'], $second['id'], 'Middle');

        $third = $this->addChapter($mid['version'], 'Three');
        $last = $this->addLevelTo($third['version'], $third['id'], 'Last');

        $version = $this->saveMap($last['version'], [
            $this->node($one, $this->levelOf($one), [$mid['nodeId']]),
            $this->node($two, $this->levelOf($two), []),
        ])->assertOk()->json('version');

        $this->saveMapOf($second['id'], $version, [
            $this->node($mid['nodeId'], $this->levelOf($mid['nodeId']), [$last['nodeId']]),
        ])->assertOk();
    }

    /**
     * Импорт — вход снаружи, и мимо проверок он не проходит.
     *
     * Правки из редактора идут через сохранение карты, где правила стоят сами.
     * Файл собирается кем угодно чем угодно и попадает в другой конструктор,
     * поэтому спрашивать надо отдельно — иначе кольцо въезжает в аккаунт целым.
     */
    public function testABundleThatLoopsIsRefusedOnImport(): void
    {
        $this->postJson('/api/library/import', $this->bundle([
            ['id' => 'n1', 'levelId' => 'lv1', 'x' => 10, 'y' => 50, 'next' => ['n2']],
            ['id' => 'n2', 'levelId' => 'lv2', 'x' => 30, 'y' => 50, 'next' => ['n1']],
        ]))->assertStatus(422)->assertJsonPath('error.code', 'invalid');
    }

    /** Тот же вход, но история честная — заезжает. */
    public function testASoundBundleStillImports(): void
    {
        $this->postJson('/api/library/import', $this->bundle([
            ['id' => 'n1', 'levelId' => 'lv1', 'x' => 10, 'y' => 50, 'next' => ['n2']],
            ['id' => 'n2', 'levelId' => 'lv2', 'x' => 30, 'y' => 50, 'next' => []],
        ]))->assertStatus(201);
    }

    /**
     * @param list<array<string, mixed>> $nodes
     *
     * @return array<string, mixed>
     */
    private function bundle(array $nodes): array
    {
        return [
            'format' => 'goo-bundle',
            'version' => 1,
            'kind' => 'library',
            'stories' => [[
                'id' => 'st-x',
                'title' => 'Imported',
                'cover' => '#000',
                'chapters' => ['ch-x'],
                'hot' => [],
            ]],
            'chapters' => [[
                'id' => 'ch-x',
                'title' => 'Chapter',
                'image' => '#123',
                'nodes' => $nodes,
                'hot' => [],
            ]],
            'levels' => [
                $this->level('lv1'),
                $this->level('lv2'),
            ],
            'assets' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function level(string $id): array
    {
        return [
            'id' => $id,
            'name' => $id,
            'width' => 1600,
            'height' => 900,
            'gravity' => ['x' => 0, 'y' => 1800],
            'goal' => 1,
            'entities' => [],
            'hot' => [],
        ];
    }

    /**
     * Бросок уровня на карту — это маршрут, а не только обработчик.
     *
     * Маршрут ссылался на метод, которого в контроллере не было: команда и
     * обработчик написаны, а звено между ними — нет, и всякий бросок отвечал
     * пятисоткой. Тесты этого не видели, потому что звали обработчик напрямую.
     * Поэтому здесь именно запрос.
     */
    public function testPinningAnExistingLevelOntoAMapGoesThroughTheRoute(): void
    {
        [$version, $one] = $this->storyOfTwoPoints();
        $levelId = $this->levelOf($one);

        $made = $this->postJson("/api/stories/{$this->storyId}/points", [
            'chapterId' => $this->chapterId,
            'levelId' => $levelId,
            'x' => 70,
            'y' => 30,
            'version' => $version,
        ])->assertStatus(201)->json();

        self::assertNotEmpty($made['nodeId']);

        // Тот же уровень теперь стоит на карте дважды — в этом весь смысл точки:
        // она показывает уровень, а не заводит его.
        $nodes = $this->getJson("/api/stories/{$this->storyId}")->json('chapters.0.nodes');
        $onThisLevel = array_filter($nodes, static fn (array $n): bool => $n['levelId'] === $levelId);

        self::assertCount(2, $onThisLevel);
    }

    /**
     * Мелкие правки не требуют версии и не спорят между собой.
     *
     * Ради этого всё и переделывалось: раньше любая правка карты уезжала картой
     * целиком и с номером версии, который клиент был обязан угадать.
     */
    public function testTwoDevicesMayEditTheSameChapterAtOnce(): void
    {
        [, $one, $two] = $this->storyOfTwoPoints();

        // Одно устройство двигает первую точку, другое — вторую. Ни одно не
        // знает о другом, и версии ни одно не присылает.
        $this->patchJson("/api/stories/{$this->storyId}/chapters/{$this->chapterId}/nodes/{$one}", [
            'x' => 10, 'y' => 90,
        ])->assertOk();

        $this->patchJson("/api/stories/{$this->storyId}/chapters/{$this->chapterId}/nodes/{$two}", [
            'name' => 'Второе место', 'image' => '#abc',
        ])->assertOk();

        $nodes = collect($this->getJson("/api/stories/{$this->storyId}")->json('chapters.0.nodes'));

        self::assertSame(10.0, (float) $nodes->firstWhere('id', $one)['x'], 'переезд лёг');
        self::assertSame('Второе место', $nodes->firstWhere('id', $two)['name'], 'подпись легла');

        // И одна правка не затёрла другую.
        self::assertSame(90.0, (float) $nodes->firstWhere('id', $one)['y']);
    }

    /** Связь — своя операция, и кольцо она по-прежнему не пропускает. */
    public function testLinkingIsItsOwnWriteAndStillRefusesLoops(): void
    {
        [, $one, $two] = $this->storyOfTwoPoints();

        $this->postJson("/api/stories/{$this->storyId}/links", ['from' => $one, 'to' => $two])
            ->assertStatus(201);

        // Обратная связь замкнула бы кольцо — отказ приходит на самой связи, а
        // не на сохранении всей карты, где о причине пришлось бы догадываться.
        $this->postJson("/api/stories/{$this->storyId}/links", ['from' => $two, 'to' => $one])
            ->assertStatus(422);

        // Отказ ничего не испортил: первая связь на месте.
        $nodes = collect($this->getJson("/api/stories/{$this->storyId}")->json('chapters.0.nodes'));
        self::assertSame([$two], $nodes->firstWhere('id', $one)['next']);

        // Снять связь можно всегда.
        $this->deleteJson("/api/stories/{$this->storyId}/links/{$one}/{$two}")->assertOk();

        $nodes = collect($this->getJson("/api/stories/{$this->storyId}")->json('chapters.0.nodes'));
        self::assertSame([], $nodes->firstWhere('id', $one)['next']);
    }

    /** Название и фон главы — тоже своя правка. */
    public function testAChapterCanBeRenamedOnItsOwn(): void
    {
        $this->storyOfTwoPoints();

        $this->patchJson("/api/stories/{$this->storyId}/chapters/{$this->chapterId}", [
            'title' => 'Переименована',
            'image' => '#654',
        ])->assertOk();

        $chapter = $this->getJson("/api/stories/{$this->storyId}")->json('chapters.0');

        self::assertSame('Переименована', $chapter['title']);
        self::assertSame('#654', $chapter['image']);
    }

    /**
     * Один уровень не встречается дважды на одном пути.
     *
     * Точка показывает уровень, а не заводит его, поэтому один уровень законно
     * стоит в нескольких местах истории — но в разных ветвях. Подряд на одной
     * дороге это значит переиграть уже сыгранное.
     */
    public function testALevelMayNotBeMetTwiceOnOnePath(): void
    {
        [$version, $one, $two] = $this->storyOfTwoPoints();
        $levelId = $this->levelOf($one);

        // Тот же уровень ставится на карту второй точкой — это законно.
        $again = $this->postJson("/api/stories/{$this->storyId}/points", [
            'chapterId' => $this->chapterId,
            'levelId' => $levelId,
            'x' => 70,
            'y' => 30,
        ])->assertStatus(201)->json('nodeId');

        // А вот дорога от одной его точки к другой — уже повтор на одном пути.
        $this->postJson("/api/stories/{$this->storyId}/links", ['from' => $one, 'to' => $two])
            ->assertStatus(201);

        $this->postJson("/api/stories/{$this->storyId}/links", ['from' => $two, 'to' => $again])
            ->assertStatus(422)->assertJsonPath('error.code', 'invalid');
    }

    /** В разных ветвях тот же уровень встречаться может: это не один путь. */
    public function testTheSameLevelMayStandOnTwoDifferentBranches(): void
    {
        [$version, $one, $two] = $this->storyOfTwoPoints();
        $levelId = $this->levelOf($two);

        $again = $this->postJson("/api/stories/{$this->storyId}/points", [
            'chapterId' => $this->chapterId,
            'levelId' => $levelId,
            'x' => 70,
            'y' => 30,
        ])->assertStatus(201)->json('nodeId');

        // Развилка: из одной точки две дороги, на каждой — свой экземпляр.
        $this->postJson("/api/stories/{$this->storyId}/links", ['from' => $one, 'to' => $two])
            ->assertStatus(201);
        $this->postJson("/api/stories/{$this->storyId}/links", ['from' => $one, 'to' => $again])
            ->assertStatus(201);
    }

    // ---- обвязка ------------------------------------------------------------

    /** @return array{0:int,1:string,2:string} версия и две точки */
    private function storyOfTwoPoints(): array
    {
        $made = $this->postJson('/api/stories', [
            'title' => 'Loops',
            'cover' => '#000',
            'chapter' => ['title' => 'One', 'image' => '#123'],
        ])->assertStatus(201)->json();

        $this->storyId = $made['id'];
        $this->chapterId = $made['chapterId'];

        $first = $this->addLevel($made['version'], 'First');
        $second = $this->addLevel($first['version'], 'Second');

        return [$second['version'], $first['nodeId'], $second['nodeId']];
    }

    /** @return array<string, mixed> */
    private function addLevel(int $version, string $name): array
    {
        return $this->postJson("/api/stories/{$this->storyId}/levels", [
            'chapterId' => $this->chapterId,
            'name' => $name,
            'x' => 50,
            'y' => 50,
            'version' => $version,
        ])->assertStatus(201)->json();
    }

    /** @return array<string, mixed> */
    private function addChapter(int $version, string $title): array
    {
        return $this->postJson("/api/stories/{$this->storyId}/chapters", [
            'title' => $title,
            'image' => '#123',
            'version' => $version,
        ])->assertStatus(201)->json();
    }

    /** @return array<string, mixed> */
    private function addLevelTo(int $version, string $chapterId, string $name): array
    {
        return $this->postJson("/api/stories/{$this->storyId}/levels", [
            'chapterId' => $chapterId,
            'name' => $name,
            'x' => 50,
            'y' => 50,
            'version' => $version,
        ])->assertStatus(201)->json();
    }

    private function levelOf(string $nodeId): string
    {
        foreach ($this->getJson("/api/stories/{$this->storyId}")->json('chapters') as $chapter) {
            foreach ($chapter['nodes'] as $node) {
                if ($node['id'] === $nodeId) {
                    return $node['levelId'];
                }
            }
        }

        self::fail("точка {$nodeId} не найдена");
    }

    /**
     * @param list<string> $next
     *
     * @return array<string, mixed>
     */
    private function node(string $id, string $levelId, array $next): array
    {
        return ['id' => $id, 'levelId' => $levelId, 'x' => 50, 'y' => 50, 'next' => $next];
    }

    /** @param list<array<string, mixed>> $nodes */
    private function saveMap(int $version, array $nodes): \Illuminate\Testing\TestResponse
    {
        return $this->saveMapOf($this->chapterId, $version, $nodes);
    }

    /** @param list<array<string, mixed>> $nodes */
    private function saveMapOf(string $chapterId, int $version, array $nodes): \Illuminate\Testing\TestResponse
    {
        return $this->putJson("/api/stories/{$this->storyId}/chapters/{$chapterId}/map", [
            'title' => 'One',
            'image' => '#123',
            'nodes' => $nodes,
            'version' => $version,
        ]);
    }
}
