<?php

declare(strict_types=1);

namespace Wob\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Wob\Identity\Infrastructure\Laravel\Auth\SignedInUser;
use Wob\Library\Domain\Model\Chapter;
use Wob\Library\Domain\Model\Level;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\Repository\StoryRepository;
use Wob\Library\Domain\ValueObject\Backdrop;
use Wob\Library\Domain\ValueObject\CanvasRect;
use Wob\Library\Domain\ValueObject\ChapterId;
use Wob\Library\Domain\ValueObject\Dimensions;
use Wob\Library\Domain\ValueObject\Gravity;
use Wob\Library\Domain\ValueObject\LevelId;
use Wob\Library\Domain\ValueObject\MapNode;
use Wob\Library\Domain\ValueObject\NodeId;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Application\Command\PublishRelease;
use Wob\Publishing\Application\Handler\PublishReleaseHandler;
use Wob\Publishing\Domain\Repository\ReleaseRepository;
use Wob\Publishing\Domain\ValueObject\ReleaseId;
use Wob\Tests\TestCase;

/**
 * Доска истории доезжает до игрока — и переживает запись на диск.
 *
 * Доска — это картинка позади глав вместе с её местом и места самих глав. Всё
 * оформление, всё замораживается выпуском, и именно поэтому легко теряется
 * молча: содержимое доедет и так, а без доски игрок увидит пустой экран выбора
 * главы и не поймёт, что что-то пропало.
 *
 * Отдельная беда — запись. Снимок выпуска складывался в JSON руками, полями
 * `chapters` и `levels`, и всё, что появлялось в снимке позже, туда не
 * попадало: начало истории лежало в снимке с самого его появления и не
 * доезжало до строки ни разу. Спасало это только то, что читающий умел добрать
 * начало у живой истории — то есть заморозка не работала вовсе.
 */
final class BoardTravelsTest extends TestCase
{
    use RefreshDatabase;

    private string $authorId = '';
    private string $playerId = '';
    private string $storyId = 'st-board';

    protected function setUp(): void
    {
        parent::setUp();

        $this->authorId = $this->makeUser('author@board.test');
        $this->playerId = $this->makeUser('player@board.test');
        $this->storyWithABoard();
    }

    /** Задник и место главы лежат в базе такими, какими их положили. */
    public function testTheBoardIsWrittenDownAndReadBack(): void
    {
        $story = app(StoryRepository::class)->find(new OwnerId($this->authorId), new StoryId($this->storyId));

        self::assertNotNull($story);
        self::assertNotNull($story->backdrop());
        self::assertSame('/media/planet.png', $story->backdrop()->src);
        self::assertSame(-540.0, $story->backdrop()->at->x);
        self::assertSame(1080.0, $story->backdrop()->at->w);
    }

    /**
     * Выпуск, прочитанный заново, всё ещё несёт задник и начало.
     *
     * Это и есть проверка записи. Пока снимок жил в памяти, всё сходилось; после
     * круга через базу возвращались одни главы с уровнями.
     */
    public function testAReleaseReadBackStillCarriesTheBoard(): void
    {
        $id = $this->publish();
        $again = app(ReleaseRepository::class)->find($id);

        self::assertNotNull($again);
        self::assertNotNull($again->content->backdrop, 'задник пережил запись');
        self::assertSame('/media/planet.png', $again->content->backdrop->src);
        self::assertSame('nd-1', $again->content->startNodeId, 'и начало тоже');
    }

    /**
     * Игрок получает задник вместе с историей.
     *
     * Из выпуска, а не у живой истории: главы приезжают замороженными и стоят на
     * заднике в его же единицах. Возьми мы живой — автор передвинул планету, а
     * острова остались на месте, и висят они теперь в пустоте.
     */
    public function testAPlayerGetsTheBoardWithTheStory(): void
    {
        $this->publish();
        $this->actingAs(new SignedInUser($this->playerId));

        $this->getJson("/api/catalog/{$this->storyId}")
            ->assertOk()
            ->assertJsonPath('backdrop.src', '/media/planet.png')
            ->assertJsonPath('backdrop.w', 1080)
            ->assertJsonPath('chapters.0.icon', '/media/isle.png')
            ->assertJsonPath('chapters.0.canvas.x', -89);
    }

    /**
     * Гостю обрезают содержимое, а не оформление.
     *
     * Гейт оставляет ему одну главу и один уровень — но доску оставляет целиком:
     * иначе первое, что видит незашедший, это пустой экран, и приглашением он не
     * выглядит.
     */
    public function testAVisitorSeesTheBoardToo(): void
    {
        $this->publish();
        $this->makeCanon();
        // Выйти обратно: выпуск делает автор, а смотрит незашедший.
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/catalog/{$this->storyId}")
            ->assertOk()
            ->assertJsonPath('preview', true)
            ->assertJsonPath('backdrop.src', '/media/planet.png');
    }

    /**
     * Автор кладёт задник руками — и он ложится.
     *
     * Правило истории: всё, что кладёт импортёр, автор может задать сам. Пока
     * ручки не было, доску умел собрать только перенос чужого набора, и своя
     * история оставалась без неё навсегда.
     */
    public function testTheAuthorCanPutTheBackdropThereByHand(): void
    {
        $this->actingAs(new SignedInUser($this->authorId));

        $this->patchJson("/api/stories/{$this->storyId}", [
            'backdrop' => ['src' => '/media/new.png', 'x' => -10, 'y' => -20, 'w' => 300, 'h' => 200],
        ])->assertOk();

        $story = app(StoryRepository::class)->find(new OwnerId($this->authorId), new StoryId($this->storyId));

        self::assertSame('/media/new.png', $story?->backdrop()?->src);
        self::assertSame(-10.0, $story?->backdrop()?->at->x);
        self::assertSame(300.0, $story?->backdrop()?->at->w);
    }

    /**
     * И убирает его пустой картинкой.
     *
     * Не пропуском поля: пропуск значит «не трогать» — иначе переименовать
     * историю было бы нельзя, не прислав заодно картинку, — и снять задник им
     * не вышло бы никогда.
     */
    public function testAnEmptyPictureTakesTheBackdropAway(): void
    {
        $this->actingAs(new SignedInUser($this->authorId));

        $this->patchJson("/api/stories/{$this->storyId}", [
            'backdrop' => ['src' => '', 'x' => 0, 'y' => 0, 'w' => 0, 'h' => 0],
        ])->assertOk();

        $story = app(StoryRepository::class)->find(new OwnerId($this->authorId), new StoryId($this->storyId));

        self::assertNull($story?->backdrop());
    }

    /** Переименование задника не касается: молчание значит «не трогать». */
    public function testRenamingLeavesTheBackdropAlone(): void
    {
        $this->actingAs(new SignedInUser($this->authorId));

        $this->patchJson("/api/stories/{$this->storyId}", ['title' => 'Другое имя'])->assertOk();

        $story = app(StoryRepository::class)->find(new OwnerId($this->authorId), new StoryId($this->storyId));

        self::assertSame('Другое имя', $story?->title());
        self::assertSame('/media/planet.png', $story?->backdrop()?->src);
    }

    /** Значок главы автор тоже ставит сам. */
    public function testTheAuthorCanPutTheChapterIconThereByHand(): void
    {
        $this->actingAs(new SignedInUser($this->authorId));

        $this->patchJson("/api/stories/{$this->storyId}/chapters/ch-1/icon", ['icon' => '/media/other.png'])
            ->assertOk();

        $story = app(StoryRepository::class)->find(new OwnerId($this->authorId), new StoryId($this->storyId));

        self::assertSame('/media/other.png', $story?->chapter(new ChapterId('ch-1'))->icon());
    }

    private function publish(): ReleaseId
    {
        $this->actingAs(new SignedInUser($this->authorId));

        $release = app(PublishReleaseHandler::class)(
            new PublishRelease($this->authorId, $this->storyId),
        );

        $release->clearedByAuthor(new DateTimeImmutable());
        app(ReleaseRepository::class)->save($release);

        return $release->id;
    }

    /** Канон — это то, что достаётся незашедшему; иначе гостю не показывают ничего. */
    private function makeCanon(): void
    {
        $release = DB::table('releases')->orderByDesc('created_at')->first();
        DB::table('stories')->where('public_id', $this->storyId)->update([
            'canonical_release_id' => $release->id,
            'canonical_since' => now(),
        ]);
    }

    private function storyWithABoard(): void
    {
        $owner = new OwnerId($this->authorId);

        $level = new Level(
            new LevelId('lv-1'),
            'Уровень',
            new Dimensions(800, 600),
            new Gravity(0, 1800),
            1,
            [],
        );

        $chapter = new Chapter(
            new ChapterId('ch-1'),
            'Остров',
            '/media/island-map.png',
            [new MapNode(new NodeId('nd-1'), new LevelId('lv-1'), 25.0, 40.7)],
            [],
            '',
            // Настоящие числа первого острова оригинала: он и правда уже 80
            // единиц, из-за чего прежний порог рамки его бы не принял.
            new CanvasRect(-89.0, -341.5, 97.85, 100.74),
            '/media/isle.png',
        );

        app(StoryRepository::class)->save(new Story(
            new StoryId($this->storyId),
            $owner,
            'История',
            '#000',
            [$chapter],
            [$level],
            [],
            Story::NEW,
            'nd-1',
            '',
            new Backdrop('/media/planet.png', new CanvasRect(-540.0, -523.0, 1080.0, 448.0)),
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
