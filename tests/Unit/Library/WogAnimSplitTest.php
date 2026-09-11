<?php

declare(strict_types=1);

namespace Wob\Tests\Unit\Library;

use PHPUnit\Framework\TestCase;
use Wob\Library\Infrastructure\Foreign\WogAnim;
use Wob\Library\Infrastructure\Foreign\WogLevel;

/**
 * Разбиение покадровой анимации между дорожкой и картинкой.
 *
 * ЗАЧЕМ РАЗБИВАТЬ
 *
 * В исходном формате одна анимация несёт всё сразу: перенос, поворот, масштаб,
 * прозрачность. В движке это два разных дела:
 *
 *   перенос и поворот — движение. Оно проходит через родство и одинаково
 *     годится телу и рисунку; его место в дорожке;
 *   масштаб и прозрачность — отрисовка. Через кадр их не провезти: кадр несёт
 *     только положение. Их место у картинки.
 *
 * Проверяется договорённость целиком: что в дорожку ушло ровно движение, что в
 * картинке не осталось ничего, кроме отрисовки, что ребёнок назвал существующего
 * родителя, и что знаки перевернулись вместе с осью Y.
 *
 * ПОЧЕМУ ЗДЕСЬ, А НЕ В КЛИЕНТЕ
 *
 * Проверка приехала из `tests/animsplit.mjs` вместе с конвертером. Пока
 * конвертер был на JavaScript, ей было место рядом с ним; конвертер переехал на
 * бэк, и проверка переехала за ним, иначе она сторожила бы код, которого больше
 * нет.
 */
final class WogAnimSplitTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wob-anim-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/anim', 0o777, true);

        // Картинке нужен настоящий файл: размер конвертер читает из заголовка PNG.
        file_put_contents($this->dir . '/deco.png', self::png(100, 50));
    }

    protected function tearDown(): void
    {
        foreach (['/anim/spin.anim.binltl', '/anim/wave.anim.binltl', '/anim/pulse.anim.binltl', '/deco.png'] as $f) {
            @unlink($this->dir . $f);
        }

        @rmdir($this->dir . '/anim');
        @rmdir($this->dir);
    }

    /**
     * Чистый поворот: всё уходит в дорожку.
     *
     * Так устроены 197 из 282 применений в наборе — подавляющее большинство.
     */
    public function testAPureRotationBecomesATrackAndLeavesThePictureAlone(): void
    {
        $this->writeAnim('spin', [1 => [[0.0, [0.0]], [0.5, [180.0]], [1.0, [360.0]]]]);

        $out = $this->convert(['anim' => 'spin']);
        $track = $this->onlyOf($out, 'track');
        $picture = $this->onlyOf($out, 'picture');

        self::assertSame($track['id'], $picture['parent'], 'картинка привязана к дорожке');
        self::assertFalse($track['data']['show'], 'дорожка ничего не рисует в игре');
        self::assertSame([], $picture['data']['anim'], 'в картинке не осталось анимации');

        // Ось Y перевёрнута — поворот тоже. Иначе всё, что крутится, крутится обратно.
        $keys = $track['data']['keys'];
        self::assertSame(-360.0, (float) end($keys)['rot'], 'поворот развёрнут вместе с осью');
        self::assertCount(3, $keys, 'кадров столько же, сколько в исходнике');

        // Строка всегда полная, даже когда двигали только поворот: пустая клетка
        // в инспекторе читается как ноль, а «не задано» и «ноль» — разные вещи.
        $row = array_keys($keys[0]);
        sort($row);
        self::assertSame(['dx', 'dy', 'rot', 't'], $row, 'строка кадра полная');
        self::assertEqualsWithDelta(1.0, $track['data']['dur'], 0.001, 'длительность круга сохранена');

        // Дорожка встаёт в середину картинки: поворот в исходнике идёт вокруг неё.
        self::assertEqualsWithDelta(
            $picture['data']['x'] + $picture['data']['w'] / 2,
            $track['data']['x'],
            0.01,
            'дорожка стоит по x в середине картинки',
        );
        self::assertEqualsWithDelta(
            $picture['data']['y'] + $picture['data']['h'] / 2,
            $track['data']['y'],
            0.01,
            'и по y',
        );

        // Сервер требует, чтобы ребёнок назвал родителя из того же уровня.
        // Порядок он не проверяет, но читать пакет сверху вниз должно быть можно.
        self::assertLessThan(
            $this->indexOf($out, $picture['id']),
            $this->indexOf($out, $track['id']),
            'дорожка стоит перед картинкой',
        );
    }

    /** Смешанная анимация: движение — дорожке, отрисовка — картинке. */
    public function testAMixedAnimationIsSplitDownTheMiddle(): void
    {
        $this->writeAnim('wave', [
            1 => [[0.0, [-4.0]], [1.0, [0.0]], [2.0, [4.0]]],
            2 => [[0.0, [4.0, 10.0]], [1.0, [0.0, 5.0]], [2.0, [-4.0, 0.0]]],
            0 => [[0.0, [1.1, 1.0]], [2.0, [1.0, 1.0]]],
        ], alpha: [[0.0, 255], [2.0, 128]]);

        $out = $this->convert(['anim' => 'wave']);
        $track = $this->onlyOf($out, 'track');
        $picture = $this->onlyOf($out, 'picture');

        $moving = array_keys($track['data']['keys'][0]);
        sort($moving);
        self::assertSame(['dx', 'dy', 'rot', 't'], $moving, 'в дорожке — движение');

        $drawing = array_keys($picture['data']['anim'][0]);
        sort($drawing);
        self::assertSame(['alpha', 'sx', 'sy', 't'], $drawing, 'в картинке — только отрисовка');

        // Масштаб по высоте в исходнике не менялся — значит единица, а не ноль.
        self::assertSame(1.0, (float) $picture['data']['anim'][0]['sy'], 'несказанное взяло нейтральное, а не ноль');
        self::assertEqualsWithDelta($track['data']['dur'], $picture['data']['animDur'], 0.001, 'длительности сошлись');

        self::assertSame(-10.0, (float) $track['data']['keys'][0]['dy'], 'перенос по y развёрнут');
        self::assertSame(4.0, (float) $track['data']['keys'][0]['dx'], 'перенос по x не тронут');
    }

    /** Только масштаб — дорожка не нужна вовсе. */
    public function testAnAnimationThatOnlyScalesNeedsNoTrack(): void
    {
        $this->writeAnim('pulse', [0 => [[0.0, [1.0, 1.0]], [1.0, [1.5, 1.0]]]]);

        $out = $this->convert(['anim' => 'pulse']);
        $picture = $this->onlyOf($out, 'picture');

        self::assertSame([], $this->allOf($out, 'track'), 'пустая дорожка не заводится');
        self::assertArrayNotHasKey('parent', $picture, 'картинка ни к кому не привязана');

        $frames = $picture['data']['anim'];
        self::assertSame(1.5, (float) end($frames)['sx'], 'масштаб остался у картинки');
    }

    public function testWithoutAnAnimationNothingChanges(): void
    {
        $out = $this->convert([]);

        self::assertSame([], $this->allOf($out, 'track'), 'без анимации дорожек нет');
        self::assertSame([], $this->onlyOf($out, 'picture')['data']['anim'], 'и картинка сама по себе');
    }

    /**
     * Кадры сводятся на объединение моментов, недостающее берётся выборкой.
     *
     * Между точками всё равно линейно, так что новых изломов это не выдумывает,
     * а строка остаётся полной.
     */
    public function testRowsAreMergedOntoTheUnionOfMoments(): void
    {
        $keys = WogAnim::keysOf(
            ['dx' => [[0.0, 0.0], [1.0, 10.0], [2.0, 20.0]], 'dy' => [[0.0, 0.0], [2.0, 40.0]]],
            ['dx' => 0.0, 'dy' => 0.0],
        );

        self::assertSame([0.0, 1.0, 2.0], array_map(static fn (array $k): float => (float) $k['t'], $keys));
        self::assertEqualsWithDelta(20.0, (float) $keys[1]['dy'], 0.001, 'досчитанное легло между соседними');
    }

    // --- сборка ------------------------------------------------------------

    /**
     * Собрать уровень из одного слоя сцены.
     *
     * @param array<string, string> $extra
     *
     * @return list<array<string, mixed>>
     */
    private function convert(array $extra): array
    {
        // Сцена 1000×1000 с одним слоем. Середина картинки в исходнике —
        // (400, 600); ось Y перевёрнута, значит в мире это (400, 400).
        $scene = [
            'tag' => 'scene',
            'attrs' => ['minx' => '0', 'maxx' => '1000', 'miny' => '0', 'maxy' => '1000'],
            'children' => [[
                'tag' => 'SceneLayer',
                'attrs' => ['image' => 'IMAGE_DECO', 'x' => '400', 'y' => '600', 'depth' => '0'] + $extra,
                'children' => [],
            ]],
        ];

        $made = (new WogLevel(
            'проба',
            $scene,
            ['tag' => 'level', 'attrs' => [], 'children' => []],
            ['images' => ['IMAGE_DECO' => 'deco'], 'sounds' => []],
            $this->dir,
            [],
            [],
            [],
            [],
            static function (string $what, int $count = 1): void {
            },
        ))->convert();

        return $made['level']['entities'];
    }

    /**
     * @param list<array<string, mixed>> $entities
     *
     * @return list<array<string, mixed>>
     */
    private function allOf(array $entities, string $type): array
    {
        return array_values(array_filter($entities, static fn (array $e): bool => $e['type'] === $type));
    }

    /**
     * @param list<array<string, mixed>> $entities
     *
     * @return array<string, mixed>
     */
    private function onlyOf(array $entities, string $type): array
    {
        $found = $this->allOf($entities, $type);
        self::assertCount(1, $found, "ожидалась ровно одна сущность «{$type}»");

        return $found[0];
    }

    /** @param list<array<string, mixed>> $entities */
    private function indexOf(array $entities, string $id): int
    {
        foreach ($entities as $i => $e) {
            if ($e['id'] === $id) {
                return $i;
            }
        }

        self::fail("сущности «{$id}» нет в уровне");
    }

    /**
     * Собрать двоичный файл анимации.
     *
     * Формат — заголовок указателей, затем по массиву кадров на дорожку.
     * Складывать его руками неприятно, но лучше, чем брать настоящий файл из
     * набора: тогда проверка знала бы, что она смотрит, только приблизительно, а
     * здесь каждое число в ней поставлено нарочно.
     *
     * @param array<int, list<array{0: float, 1: list<float>}>> $tracks вид → кадры
     * @param list<array{0: float, 1: int}>                     $alpha
     */
    private function writeAnim(string $name, array $tracks, array $alpha = []): void
    {
        $times = [];

        foreach ($tracks as $frames) {
            foreach ($frames as [$t]) {
                $times[] = $t;
            }
        }

        foreach ($alpha as [$t]) {
            $times[] = $t;
        }

        $times = array_values(array_unique($times));
        sort($times);
        $frames = count($times);
        $slot = static fn (float $t): int => (int) array_search($t, $times, true);

        // Сперва тело: значения кадров. Указатели на них считаются от начала
        // файла, поэтому заголовок фиксированной длины идёт первым.
        $head = 44;
        $body = '';
        // Замыкание должно видеть растущее тело, а не его снимок: стрелочная
        // функция забирает переменную по значению в момент объявления, и с ней
        // каждый указатель равнялся бы началу файла.
        $at = static function () use ($head, &$body): int {
            return $head + strlen($body);
        };

        $pTimes = $at();

        foreach ($times as $t) {
            $body .= pack('g', $t);
        }

        $pTypes = $at();

        foreach (array_keys($tracks) as $kind) {
            $body .= pack('l', $kind);
        }

        // Значения кадров каждой дорожки, а следом массив указателей на них.
        $rows = [];

        foreach ($tracks as $kind => $frameList) {
            $slots = array_fill(0, $frames, 0);

            foreach ($frameList as [$t, $values]) {
                $slots[$slot($t)] = $at();
                // У поворота угол лежит третьим числом, у остальных первыми двумя.
                $body .= $kind === 1
                    ? pack('ggg', 0.0, 0.0, $values[0])
                    : pack('gg', $values[0], $values[1] ?? 0.0);
            }

            $rows[] = $slots;
        }

        $pointers = [];

        foreach ($rows as $slots) {
            $pointers[] = $at();

            foreach ($slots as $p) {
                $body .= pack('l', $p);
            }
        }

        $pXform = $at();

        foreach ($pointers as $p) {
            $body .= pack('l', $p);
        }

        $pAlpha = 0;

        if ($alpha !== []) {
            $slots = array_fill(0, $frames, 0);

            foreach ($alpha as [$t, $value]) {
                $slots[$slot($t)] = $at();
                $body .= pack('llll', 0, 0, 0, $value);
            }

            $pAlpha = $at();

            foreach ($slots as $p) {
                $body .= pack('l', $p);
            }
        }

        $header = pack(
            'llllllllll',
            0,                      // 0  есть ли цвет
            $alpha === [] ? 0 : 1,  // 4  есть ли прозрачность
            0,                      // 8  не используется
            1,                      // 12 есть ли преобразования
            count($tracks),         // 16 сколько их
            $frames,                // 20 сколько кадров
            $pTypes,                // 24
            $pTimes,                // 28
            $pXform,                // 32
            $pAlpha,                // 36
        ) . pack('l', 0);           // 40 цвет

        file_put_contents($this->dir . "/anim/{$name}.anim.binltl", $header . $body);
    }

    /** Заголовок PNG: конвертеру нужен только размер. */
    private static function png(int $w, int $h): string
    {
        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', $w, $h) . str_repeat("\0", 5);
    }
}
