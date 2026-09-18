<?php

declare(strict_types=1);

namespace Wob\Tests\Unit\Library;

use PHPUnit\Framework\TestCase;
use Wob\Library\Infrastructure\Foreign\WogBoard;
use Wob\Library\Infrastructure\Foreign\WogFile;

/**
 * Экраны выбора: карта мира и карты островов.
 *
 * Раньше их выбрасывали целиком — в списке уровней таких папок нет, значит и
 * смотреть нечего, — и в конвертере даже стояло, будто раскладки точек в наборе
 * не существует. Существует: у каждого острова своя кнопка на карте мира со
 * своим углом, у каждого уровня своя кнопка на карте острова.
 *
 * Проверяется здесь то, что глазами на скриншоте не видно, а промахнуться можно
 * молча: чья картинка берётся у кнопки, куда её класть, и что происходит с
 * углом, под которым её поставил автор.
 */
final class WogBoardTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/board-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/images', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*/*') ?: [] as $file) {
            unlink($file);
        }

        foreach (glob($this->root . '/*') ?: [] as $dir) {
            is_dir($dir) ? rmdir($dir) : unlink($dir);
        }

        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    /**
     * Цветная картинка кнопки, а не та, что лежит в `up`.
     *
     * В оригинале у кнопки три состояния: `up` — чёрный силуэт, `over` —
     * цветной остров, `disabled` — снова силуэт. Остров там чёрный всегда и
     * расцветает только под курсором. У нас картинка одна, а чернит её фильтр —
     * и чернит ровно тогда, когда главы ещё нет. Возьми мы `up`, открытая глава
     * осталась бы силуэтом и от закрытой её было бы не отличить.
     */
    public function testAButtonBringsTheColouredPictureNotTheBlackOne(): void
    {
        $this->picture('images/c1_up.png', 40, 20);
        $this->picture('images/c1_over.png', 40, 20);

        $buttons = WogBoard::buttons(
            $this->scene('<button id="island1" x="100" y="200" scalex="1" scaley="1" rotation="0"'
                . ' up="IMAGE_C1_UP" over="IMAGE_C1_OVER" />'),
            ['IMAGE_C1_UP' => 'images/c1_up', 'IMAGE_C1_OVER' => 'images/c1_over'],
            $this->root,
        );

        self::assertSame('images/c1_over.png', $buttons['island1']['src']);
    }

    /** Без цветной берётся какая есть: чужой набор не обязан звать их так же. */
    public function testWithoutAColouredPictureTheOtherOneWillDo(): void
    {
        $this->picture('images/c1_up.png', 40, 20);

        $buttons = WogBoard::buttons(
            $this->scene('<button id="island1" x="0" y="0" up="IMAGE_C1_UP" />'),
            ['IMAGE_C1_UP' => 'images/c1_up'],
            $this->root,
        );

        self::assertSame('images/c1_up.png', $buttons['island1']['src']);
    }

    /**
     * Место кнопки: там середина и y вверх, у нас угол и y вниз.
     *
     * Две перемены разом, и обе молчат, если ошибиться: остров просто окажется
     * не там. Картинка 40×20 с серединой в (100, 200) занимает у нас
     * (80, −210)…(120, −190).
     */
    public function testAButtonLandsByItsCornerAndTheWorldTurnsOver(): void
    {
        $this->picture('images/c1.png', 40, 20);

        $spot = WogBoard::buttons(
            $this->scene('<button id="island1" x="100" y="200" scalex="1" scaley="1" rotation="0" up="IMAGE_C1" />'),
            ['IMAGE_C1' => 'images/c1'],
            $this->root,
        )['island1'];

        self::assertSame(80.0, $spot['x']);
        self::assertSame(-210.0, $spot['y']);
        self::assertSame(40.0, $spot['w']);
        self::assertSame(20.0, $spot['h']);
    }

    /** Масштаб кнопки — это размер картинки, а не отдельная величина у нас. */
    public function testAButtonIsAsBigAsItsPictureTimesItsScale(): void
    {
        $this->picture('images/c1.png', 40, 20);

        $spot = WogBoard::buttons(
            $this->scene('<button id="island1" x="0" y="0" scalex="0.5" scaley="2" up="IMAGE_C1" />'),
            ['IMAGE_C1' => 'images/c1'],
            $this->root,
        )['island1'];

        self::assertSame(20.0, $spot['w']);
        self::assertSame(40.0, $spot['h']);
    }

    /**
     * Повёрнутая кнопка занимает место повёрнутой картинки, а не исходной.
     *
     * Поля под угол у главы нет и не будет — кнопку не вращают, вращают картинку
     * в редакторе, — поэтому угол запекается в сам PNG. Значит и место обязано
     * считаться по тому, что вышло: 40×20, повёрнутые на 90°, это 20×40.
     */
    public function testATurnedButtonTakesTheRoomItsTurnedPictureNeeds(): void
    {
        $this->picture('images/c1.png', 40, 20);

        $spot = WogBoard::buttons(
            $this->scene('<button id="island1" x="0" y="0" scalex="1" scaley="1" rotation="90" up="IMAGE_C1" />'),
            ['IMAGE_C1' => 'images/c1'],
            $this->root,
        )['island1'];

        self::assertEqualsWithDelta(20.0, $spot['w'], 0.01);
        self::assertEqualsWithDelta(40.0, $spot['h'], 0.01);
        self::assertSame(90.0, $spot['rot']);
    }

    /** И сам файл действительно поворачивается, а не только считается. */
    public function testTheTurnedPictureIsReallyTurned(): void
    {
        $this->picture('images/c1.png', 40, 20);

        $made = WogBoard::turned($this->root . '/images/c1.png', 90, $this->root . '/board', 'icon-1');

        self::assertNotNull($made);
        $size = WogFile::pngSize($made);
        self::assertSame(20, $size['w']);
        self::assertSame(40, $size['h']);
    }

    /**
     * Нулевой угол оставляет файл в покое.
     *
     * Перерисовать PNG ради поворота на ноль значит потерять в качестве там, где
     * меняться нечему, и положить в набор лишний файл.
     */
    public function testAPictureWithNoAngleIsLeftAlone(): void
    {
        $this->picture('images/c1.png', 40, 20);
        $was = $this->root . '/images/c1.png';

        self::assertSame($was, WogBoard::turned($was, 0, $this->root . '/board', 'icon-1'));
    }

    /**
     * Кнопки собираются и из групп, и мимо них.
     *
     * Группа в оригинале — про то, как по кнопкам ходит джойстик (`osx="130,1.2"`),
     * а не про то, что они разные. Шестой остров лежит вне всякой группы, и
     * деление по этому признаку стоило бы главы.
     */
    public function testButtonsCountWhetherOrNotTheySitInAGroup(): void
    {
        $this->picture('images/c1.png', 10, 10);

        $buttons = WogBoard::buttons(
            $this->scene(
                '<buttongroup id="mainbuttongroup" osx="130,1.2">'
                . '<button id="island1" x="0" y="0" up="IMAGE_C1" />'
                . '</buttongroup>'
                . '<button id="island6" x="0" y="0" up="IMAGE_C1" />',
            ),
            ['IMAGE_C1' => 'images/c1'],
            $this->root,
        );

        self::assertArrayHasKey('island1', $buttons);
        self::assertArrayHasKey('island6', $buttons);
    }

    /**
     * Задник собирается из неподвижных слоёв, а вращающиеся остаются за бортом.
     *
     * Застывший кадр вместо вращения — это не «почти то же самое», это
     * неподвижная мельница. И раз она не поехала, об этом надо сказать вслух, а
     * не промолчать: отчёт о непереносимом — часть результата.
     */
    public function testTheBackdropTakesTheStillLayersAndSaysWhatItLeft(): void
    {
        $this->picture('images/planet.png', 60, 60);
        $this->picture('images/mill.png', 20, 20);
        $this->picture('images/bars.png', 100, 8);

        $said = [];
        $frame = WogBoard::backdrop(
            $this->scene(
                '<SceneLayer name="globe_shadow" depth="0" x="0" y="0" scalex="1" scaley="1" rotation="0"'
                . ' alpha="1" colorize="255,255,255" image="IMAGE_PLANET" />'
                . '<SceneLayer name="windmill" depth="-1" x="0" y="0" scalex="1" scaley="1" rotation="0"'
                . ' alpha="1" colorize="0,0,0" image="IMAGE_MILL" anim="rot_1rps" animspeed="-0.25" />'
                . '<SceneLayer name="letterbox_top" depth="0" x="0" y="0" scalex="1" scaley="1" rotation="0"'
                . ' alpha="1" colorize="255,255,255" image="IMAGE_BARS" />',
            ),
            ['IMAGE_PLANET' => 'images/planet', 'IMAGE_MILL' => 'images/mill', 'IMAGE_BARS' => 'images/bars'],
            $this->root,
            $this->root . '/board',
            'story',
            static function (string $what, int $count = 1) use (&$said): void {
                $said[$what] = ($said[$what] ?? 0) + $count;
            },
        );

        self::assertNotNull($frame);
        // Только планета: мельница крутится, полосы — устройство чужого окна.
        self::assertSame(60.0, $frame['w']);
        self::assertSame(60.0, $frame['h']);
        self::assertSame(1, $said['слой экрана выбора вращается — в задник он не встал'] ?? 0);
    }

    /**
     * Рамка задника считается по слоям, и y переворачивается так же, как у кнопок.
     *
     * Отдать картинку без рамки значило бы предложить читающему гадать, куда её
     * класть, — и промахнуться ровно на столько, на сколько задник несимметричен.
     * А главы стоят на нём в тех же числах.
     */
    public function testTheBackdropKnowsWhereItLies(): void
    {
        $this->picture('images/a.png', 40, 20);
        $this->picture('images/b.png', 40, 20);

        $frame = WogBoard::backdrop(
            $this->scene(
                '<SceneLayer name="a" depth="0" x="0" y="0" scalex="1" scaley="1" image="IMAGE_A" />'
                . '<SceneLayer name="b" depth="1" x="100" y="50" scalex="1" scaley="1" image="IMAGE_B" />',
            ),
            ['IMAGE_A' => 'images/a', 'IMAGE_B' => 'images/b'],
            $this->root,
            $this->root . '/board',
            'story',
            static fn (string $what, int $count = 1): null => null,
        );

        self::assertNotNull($frame);
        // x от −20 до 120, y в их осях от −10 до 60 — у нас сверху вниз от −60.
        self::assertSame(-20.0, $frame['x']);
        self::assertSame(-60.0, $frame['y']);
        self::assertSame(140.0, $frame['w']);
        self::assertSame(70.0, $frame['h']);
    }

    /**
     * Задник карты главы доводится до 16:9 прозрачным полем.
     *
     * Карта рисуется в рамке 16:9 с обрезкой по краям, а точки стоят в долях
     * этой рамки. Не совпади пропорции — задник срежется, точки нет, и вся
     * раскладка уедет ровно на срезанное, молча.
     *
     * Поле растёт в обе стороны от середины, и место обязано поехать вместе с
     * ним — иначе задник встанет со сдвигом ровно на это поле.
     */
    public function testTheChapterBackdropIsPaddedToTheFrameItIsDrawnIn(): void
    {
        $this->picture('images/isle.png', 160, 160);

        $was = ['src' => $this->root . '/images/isle.png', 'x' => 0.0, 'y' => 0.0, 'w' => 160.0, 'h' => 160.0];
        $now = WogBoard::padded($was, 16 / 9, $this->root . '/board', 'map-1');

        self::assertNotNull($now);
        self::assertSame(284.0, $now['w']);
        self::assertSame(160.0, $now['h']);
        self::assertEqualsWithDelta(16 / 9, $now['w'] / $now['h'], 0.01);
        // Дорисовано по 62 с каждой стороны, значит левый край уехал влево.
        self::assertSame(-62.0, $now['x']);
        self::assertSame(0.0, $now['y']);
    }

    /** Уже в тех пропорциях — ничего не переписывается. */
    public function testAlreadyTheRightShapeIsLeftAlone(): void
    {
        $this->picture('images/isle.png', 160, 90);

        $was = ['src' => $this->root . '/images/isle.png', 'x' => 5.0, 'y' => 7.0, 'w' => 160.0, 'h' => 90.0];

        self::assertSame($was, WogBoard::padded($was, 16 / 9, $this->root . '/board', 'map-1'));
    }

    /** @param array<string, mixed> $_ */
    private function picture(string $rel, int $w, int $h): void
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, (int) imagecolorallocatealpha($img, 10, 200, 60, 0));
        imagepng($img, $this->root . '/' . $rel);
        imagedestroy($img);
    }

    /** @return array<string, mixed> */
    private function scene(string $inside): array
    {
        return WogFile::parseXml('<scene minx="-100" miny="0" maxx="100" maxy="100">' . $inside . '</scene>');
    }
}
