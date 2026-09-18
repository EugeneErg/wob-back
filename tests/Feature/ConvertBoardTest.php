<?php

declare(strict_types=1);

namespace Wob\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Wob\Library\Infrastructure\Foreign\WogFile;
use Wob\Tests\TestCase;

/**
 * Карта мира и карты островов доезжают до глав и точек.
 *
 * Отдельным набором, а не поверх соседнего: там островов нет вовсе, и это тоже
 * проверяется — конвертер обязан работать на чужом наборе, собранном иначе.
 * Здесь же набор устроен как оригинальный: карта мира с кнопкой на остров,
 * остров с кнопками на уровни, и заголовок вокруг планеты.
 *
 * Проверяется именно перенос, а не рисование: у `WogBoard` свои проверки. Тут
 * вопрос один — попало ли прочитанное в главу и в её точки, или осталось лежать
 * в конвертере. Ровно это и было сломано: читатель пакета не брал у главы ни
 * места, ни картинки, хотя в файле они были.
 */
final class ConvertBoardTest extends TestCase
{
    use RefreshDatabase;

    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/wogset-' . bin2hex(random_bytes(6));
        $this->buildSet();
    }

    protected function tearDown(): void
    {
        self::sweep($this->root);
        parent::tearDown();
    }

    public function testAChapterGetsItsPictureAndItsPlaceFromTheWorldMap(): void
    {
        $chapter = $this->convert()['chapters'][0];

        // Кнопка острова стоит серединой в (100, 300) и имеет 48×24 после
        // масштаба 0.5; у нас это угол (76, −312) и y вниз.
        self::assertEqualsWithDelta(76, $chapter['canvas']['x'], 0.01);
        self::assertEqualsWithDelta(-312, $chapter['canvas']['y'], 0.01);
        self::assertEqualsWithDelta(48, $chapter['canvas']['w'], 0.01);
        self::assertEqualsWithDelta(24, $chapter['canvas']['h'], 0.01);
        self::assertNotSame('', $chapter['icon']);
        self::assertFileExists($chapter['icon']);
    }

    /**
     * Точки стоят там, где их нарисовал художник.
     *
     * Раньше они раскладывались по глубине зависимостей — цепочка вправо, ветка
     * вниз, — и в конвертере стояло, будто раскладки в наборе нет. Она есть, и
     * от неё зависит, лягут ли точки на трубы острова или рядом с ними.
     */
    public function testPointsStandWhereTheIslandPutsThem(): void
    {
        $bundle = $this->convert();
        $chapter = $bundle['chapters'][0];
        $dots = [];

        foreach ($chapter['nodes'] as $node) {
            $dots[$node['levelId']] = [$node['x'], $node['y']];
        }

        /*
          Задник острова — слой 200×100 с серединой в (0, 50), то есть от −100
          до 100 вширь и от −100 до 0 у нас вглубь. Полем он доведён до 16:9,
          и растёт при этом высота: 200×113, по 6 сверху и снизу. Рамка выходит
          (−100, −106) размером 200×113.

          Кнопка первого уровня стоит серединой в (−50, 60) их осей, у нас это
          (−50, −60). Отсюда четверть вширь и 40.7 вглубь — не половина, потому
          что остров нарисован выше середины своей рамки.
        */
        self::assertEqualsWithDelta(25.0, $dots['wog-first'][0], 0.6);
        self::assertEqualsWithDelta(40.7, $dots['wog-first'][1], 0.6);
        self::assertEqualsWithDelta(75.0, $dots['wog-second'][0], 0.6);

        // Все точки на месте, и ни одна не попала в выдуманную сетку (6, 10).
        self::assertCount(3, $dots);

        foreach ($dots as $dot) {
            self::assertNotSame([6.0, 10.0], $dot);
        }
    }

    /** Задник главы — сам остров, собранный из его слоёв. */
    public function testAChapterGetsTheIslandAsItsBackdrop(): void
    {
        $chapter = $this->convert()['chapters'][0];

        self::assertNotSame('', $chapter['image']);
        self::assertFileExists($chapter['image']);

        $size = WogFile::pngSize($chapter['image']);
        // Доведён до рамки, в которой карта рисуется, иначе точки уедут.
        self::assertEqualsWithDelta(16 / 9, $size['w'] / $size['h'], 0.02);
    }

    /**
     * У истории появляется задник: планета с заголовком, одной картинкой.
     *
     * Вместе с местом, а не отдельно: главы стоят на нём в тех же числах, и
     * задник без рамки пришлось бы совмещать с ними на глаз.
     */
    public function testTheStoryGetsThePlanetWithItsTitleAsOnePicture(): void
    {
        $story = $this->convert()['stories'][0];

        self::assertNotNull($story['backdrop']);
        self::assertFileExists($story['backdrop']['src']);
        self::assertGreaterThan(0, $story['backdrop']['w']);
        self::assertGreaterThan(0, $story['backdrop']['h']);
    }

    /**
     * `depends="A,B"` — список, а не имя уровня.
     *
     * Читался он целой строкой; совпадения не находилось, тропа не рисовалась, и
     * уровень оказывался открыт с самого начала наравне с первым. В наборе
     * оригинала так устроены четыре уровня из сорока семи.
     */
    public function testALevelWaitingForTwoOthersGetsAPathFromEachOfThem(): void
    {
        $nodes = [];

        foreach ($this->convert()['chapters'][0]['nodes'] as $node) {
            $nodes[$node['levelId']] = $node;
        }

        self::assertContains($nodes['wog-third']['id'], $nodes['wog-first']['next'], 'тропа от первого');
        self::assertContains($nodes['wog-third']['id'], $nodes['wog-second']['next'], 'тропа от второго');
    }

    /**
     * Прочитанное доезжает до базы, а не остаётся в файле.
     *
     * Ровно это и было сломано: `BundleReader` строил главу из названия, фона и
     * точек, а место на доске и картинку там молча не брал — поля в файле были,
     * читатель их не видел. Наружу это выходило тем, что импортированная история
     * показывала игроку пустой экран выбора главы.
     */
    public function testWhatWasReadReachesTheLibrary(): void
    {
        $out = $this->root . '/bundle.json';

        $this->artisan('wob:convert', [
            'source' => $this->root,
            '--out' => $out,
            '--films' => $this->root . '/films',
            '--quiet-report' => true,
        ])->assertSuccessful();

        $this->artisan('wob:import', ['file' => $out, '--user' => 'board@wob.test'])->assertSuccessful();

        $chapter = DB::table('chapters')->first();
        self::assertNotNull($chapter);
        self::assertNotSame('', $chapter->icon);
        self::assertEqualsWithDelta(76, (float) $chapter->canvas_x, 0.01);
        self::assertEqualsWithDelta(-312, (float) $chapter->canvas_y, 0.01);
        self::assertNotSame('', $chapter->image);

        $story = DB::table('stories')->first();
        self::assertNotNull($story);
        self::assertNotSame('', $story->backdrop, 'задник истории');
        self::assertGreaterThan(0, (float) $story->backdrop_w);
    }

    /** @return array<string, mixed> */
    private function convert(): array
    {
        $out = $this->root . '/bundle.json';

        $this->artisan('wob:convert', [
            'source' => $this->root,
            '--out' => $out,
            '--films' => $this->root . '/films',
            '--quiet-report' => true,
        ])->assertSuccessful();

        return (array) json_decode((string) file_get_contents($out), true);
    }

    /**
     * Набор из двух уровней, одного острова и карты мира.
     *
     * Написан здесь, а не положен файлами: файлы набора зашифрованы, и лежащий
     * в репозитории шифрованный кусочек нельзя ни прочесть глазами, ни поправить
     * — а понять, что именно проверяется, надо уметь не запуская.
     */
    private function buildSet(): void
    {
        foreach (['properties', 'islands', 'images', 'levels/first', 'levels/second', 'levels/third',
            'levels/island1', 'levels/MapWorldView'] as $dir) {
            mkdir($this->root . '/' . $dir, 0o777, true);
        }

        $this->png('images/isle_up.png', 96, 48);
        $this->png('images/isle_over.png', 96, 48);
        $this->png('images/marker.png', 12, 12);
        $this->png('images/planet.png', 60, 60);
        $this->png('images/title.png', 80, 30);
        $this->png('images/island_main.png', 200, 100);

        $this->lay('properties/resources.xml.bin', self::sealed(
            '<ResourceManifest><Resources id="global">'
            . '<Image id="IMAGE_ISLE_UP" path="res/images/isle_up" />'
            . '<Image id="IMAGE_ISLE_OVER" path="res/images/isle_over" />'
            . '<Image id="IMAGE_MARKER" path="res/images/marker" />'
            . '<Image id="IMAGE_PLANET" path="res/images/planet" />'
            . '<Image id="IMAGE_TITLE" path="res/images/title" />'
            . '<Image id="IMAGE_ISLAND_MAIN" path="res/images/island_main" />'
            . '</Resources></ResourceManifest>',
        ));
        $this->lay('properties/materials.xml.bin', self::sealed('<materials></materials>'));
        $this->lay('properties/fx.xml.bin', self::sealed('<effects></effects>'));

        $this->lay('islands/island1.xml.bin', self::sealed(
            '<island name="Первый остров" map="island1" icon="IMAGE_ISLE_UP">'
            . '<level id="first" name="LEVEL_NAME_FIRST" text="" ocd="balls,3" />'
            . '<level id="second" depends="first" name="" text="" />'
            // Список, а не имя: ровно тот случай, из-за которого уровень
            // оказывался открыт с самого начала.
            . '<level id="third" depends="first,second" name="" text="" />'
            . '</island>',
        ));

        foreach (['first', 'second', 'third'] as $name) {
            $this->lay("levels/{$name}/{$name}.level.bin", self::sealed('<level ballsrequired="1"></level>'));
            $this->lay("levels/{$name}/{$name}.scene.bin", self::sealed(
                '<scene minx="-100" miny="0" maxx="100" maxy="100" backgroundcolor="0,0,0"></scene>',
            ));
        }

        // Карта острова: задник и по кнопке на уровень.
        $this->lay('levels/island1/island1.level.bin', self::sealed('<level></level>'));
        $this->lay('levels/island1/island1.scene.bin', self::sealed(
            '<scene minx="-100" miny="0" maxx="100" maxy="100">'
            . '<SceneLayer name="main" depth="0" x="0" y="50" scalex="1" scaley="1" rotation="0"'
            . ' alpha="1" colorize="255,255,255" image="IMAGE_ISLAND_MAIN" />'
            . '<buttongroup id="levelMarkerGroup" osx="150,1.08">'
            . '<button id="lb_first" x="-50" y="60" scalex="1" scaley="1" rotation="0" up="IMAGE_MARKER" />'
            . '<button id="lb_second" x="50" y="40" scalex="1" scaley="1" rotation="0" up="IMAGE_MARKER" />'
            . '<button id="lb_third" x="0" y="20" scalex="1" scaley="1" rotation="0" up="IMAGE_MARKER" />'
            . '</buttongroup></scene>',
        ));

        // Карта мира: планета с заголовком и кнопка острова.
        $this->lay('levels/MapWorldView/MapWorldView.level.bin', self::sealed('<level></level>'));
        $this->lay('levels/MapWorldView/MapWorldView.scene.bin', self::sealed(
            '<scene minx="-300" miny="0" maxx="300" maxy="600">'
            . '<SceneLayer name="globe_shadow" depth="0" x="100" y="300" scalex="1" scaley="1" rotation="0"'
            . ' alpha="1" colorize="255,255,255" image="IMAGE_PLANET" />'
            . '<SceneLayer name="title" depth="-1" x="100" y="380" scalex="1" scaley="1" rotation="0"'
            . ' alpha="1" colorize="255,255,255" image="IMAGE_TITLE" />'
            . '<buttongroup id="mainbuttongroup" osx="130,1.2">'
            . '<button id="island1" x="100" y="300" scalex="0.5" scaley="0.5" rotation="0"'
            . ' up="IMAGE_ISLE_UP" over="IMAGE_ISLE_OVER" />'
            . '</buttongroup></scene>',
        ));
    }

    private function png(string $rel, int $w, int $h): void
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, (int) imagecolorallocatealpha($img, 30, 180, 90, 0));
        imagepng($img, $this->root . '/' . $rel);
        imagedestroy($img);
    }

    private function lay(string $rel, string $bytes): void
    {
        file_put_contents($this->root . '/' . $rel, $bytes);
    }

    /** Файлы набора лежат зашифрованными, и читает их конвертер только так. */
    private static function sealed(string $xml): string
    {
        $key = (new ReflectionClass(WogFile::class))->getConstant('KEY');
        $body = $xml . "\xFD";
        $body .= str_repeat("\x00", (16 - strlen($body) % 16) % 16);

        return (string) openssl_encrypt(
            $body,
            'aes-192-cbc',
            (string) $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            str_repeat("\0", 16),
        );
    }

    private static function sweep(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $dir . '/' . $name;
            is_dir($path) ? self::sweep($path) : unlink($path);
        }

        rmdir($dir);
    }
}
