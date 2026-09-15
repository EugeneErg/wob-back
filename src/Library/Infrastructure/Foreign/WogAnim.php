<?php

declare(strict_types=1);

namespace Wob\Library\Infrastructure\Foreign;

/**
 * The binary animation files, and what they become.
 *
 * A header of pointers, then one array of keyframes per track. It is not
 * described anywhere useful, so the layout here was worked out by reading the
 * bytes against known files and is not to be adjusted on a hunch.
 *
 * The split into two is the point of the second half. Movement — travel and
 * rotation — goes to a `track`, which rides through parenthood and suits a body
 * as well as a picture. Scale and transparency stay with the picture: a
 * keyframe carries a position and nothing else, so they cannot travel that way.
 */
final class WogAnim
{
    // Виды преобразования, как они записаны в файле. Масштаб — нулевой, и он
    // же случай по умолчанию: неизвестный вид разумнее прочесть как масштаб,
    // чем выбросить кадр целиком.
    private const ROTATE = 1;
    private const TRANSLATE = 2;

    /**
     * @return array{tracks: array<string, list<array{0: float, 1: float}>>, dur: float, blank: list<float>}
     */
    /**
     * @param bool $keepStill Оставлять ли дорожку, которая никуда не движется.
     *                         Картинке уровня такая не нужна: это волна без
     *                         колебания. Актёру заставки нужна, и очень:
     *                         неподвижная точка — всё равно место, а выброшенная
     *                         дорожка ставит его в начало координат.
     * @param int $from С какого места файла читать. Ноль — с начала, как у
     *                  отдельного файла анимации. У заставки таких заголовков
     *                  в одном файле столько, сколько в ней актёров, и все
     *                  смещения внутри каждого отсчитываются от его начала —
     *                  поэтому хвост файла с нужного места и есть такой файл.
     */
    /** @return array{tracks: array<string, list<array{0: float, 1: float}>>, dur: float, blank: list<float>} */
    public static function read(string $file, int $from = 0, bool $keepStill = false): array
    {
        $b = (string) file_get_contents($file);

        if ($from > 0) {
            $b = substr($b, $from);
        }
        $i32 = static fn (int $o): int => unpack('l', substr($b, $o, 4))[1] ?? 0;
        $f32 = static fn (int $o): float => (float) (unpack('g', substr($b, $o, 4))[1] ?? 0);

        $hasColor = $i32(0);
        $hasAlpha = $i32(4);
        $hasXform = $i32(12);
        $nXform = $i32(16);
        $nFrames = $i32(20);
        $pTypes = $i32(24);
        $pTimes = $i32(28);
        $pXform = $i32(32);
        $pAlpha = $i32(36);
        $pColor = $i32(40);

        $times = [];

        for ($i = 0; $i < $nFrames; $i++) {
            $times[] = $f32($pTimes + $i * 4);
        }

        $out = [];

        $put = static function (string $name, float $t, float $v) use (&$out): void {
            if (!is_finite($v)) {
                return;
            }

            $out[$name][] = [WogGeometry::fixed($t, 4), WogGeometry::fixed($v, 4)];
        };

        // A track's frames are an array of pointers, one per frame. A null
        // pointer means this track says nothing on that frame.
        //
        // `$skip` — кадры, на которых актёра нет вовсе (см. ниже). Их пропускает
        // только преобразование: яркость на таком кадре как раз и говорит, что
        // актёра не видно, и выбросить её значило бы оставить его на экране.
        $walk = static function (int $base, callable $each, array $skip = []) use ($nFrames, $i32, $times): void {
            if ($base === 0) {
                return;
            }

            for ($i = 0; $i < $nFrames; $i++) {
                if (isset($skip[$i])) {
                    continue;
                }

                $at = $i32($base + $i * 4);

                if ($at !== 0) {
                    $each($at, $times[$i] ?? 0.0);
                }
            }
        };

        // Кадр, на котором актёра нет.
        //
        // Яркость ноль, а преобразование — пустая заготовка: на месте (0, 0),
        // без поворота, в natural size. Это не положение, а его отсутствие:
        // тот, кто писал файл, просто обнулил кадр вместо того, чтобы оставить
        // пустой указатель. Отличить одно от другого можно: настоящего актёра
        // в углу экрана не держат — по всему набору заставок таких кадров 2223
        // и у всех до единого место ровно (0, 0).
        //
        // Принять их за место нельзя. Дорожка тянется через кадр насквозь, и
        // актёр на глазах уезжает в левый верхний угол и возвращается оттуда —
        // на появлении, на исчезновении и на каждом моргании. В оригинале он
        // просто гаснет там, где стоял.
        $blankAt = [];

        if ($hasAlpha !== 0 && $pAlpha !== 0) {
            for ($i = 0; $i < $nFrames; $i++) {
                $at = $i32($pAlpha + $i * 4);

                if ($at === 0 || $i32($at + 12) !== 0) {
                    continue;
                }

                $empty = true;

                for ($t = 0; $hasXform !== 0 && $t < $nXform; $t++) {
                    $key = $i32($i32($pXform + $t * 4) + $i * 4);

                    if ($key === 0) {
                        continue;
                    }

                    $empty = match ($i32($pTypes + $t * 4)) {
                        self::ROTATE => abs($f32($key + 8)) < 1e-4,
                        self::TRANSLATE => abs($f32($key)) < 1e-4 && abs($f32($key + 4)) < 1e-4,
                        default => abs($f32($key) - 1.0) < 1e-4 && abs($f32($key + 4) - 1.0) < 1e-4,
                    };

                    if (!$empty) {
                        break;
                    }
                }

                if ($empty) {
                    $blankAt[$i] = true;
                }
            }
        }

        if ($hasXform !== 0) {
            for ($t = 0; $t < $nXform; $t++) {
                $kind = $i32($pTypes + $t * 4);
                $walk($i32($pXform + $t * 4), static function (int $at, float $time) use ($kind, $f32, $put): void {
                    // Only what actually moves: for a rotation the angle, for
                    // the rest x and y.
                    match ($kind) {
                        self::ROTATE => $put('rot', $time, $f32($at + 8)),
                        self::TRANSLATE => (static function () use ($put, $time, $f32, $at): void {
                            $put('dx', $time, $f32($at));
                            $put('dy', $time, $f32($at + 4));
                        })(),
                        default => (static function () use ($put, $time, $f32, $at): void {
                            $put('sx', $time, $f32($at));
                            $put('sy', $time, $f32($at + 4));
                        })(),
                    };
                }, $blankAt);
            }
        }

        if ($hasAlpha !== 0) {
            $walk($pAlpha, static function (int $at, float $time) use ($i32, $put): void {
                $alpha = $i32($at + 12);

                // -1 means "not given", which is not the same as transparent.
                if ($alpha >= 0) {
                    $put('alpha', $time, $alpha / 255);
                }
            });
        }

        if ($hasColor !== 0) {
            $walk($pColor, static fn (int $at, float $time): null => null);
        }

        // A track holding one value, or the same value throughout, animates
        // nothing — carrying it over would give the picture a wave with no
        // motion in it.
        foreach (array_keys($out) as $k) {
            $first = $out[$k][0][1];
            $moves = false;

            foreach ($out[$k] as [, $v]) {
                if ($v !== $first) {
                    $moves = true;
                    break;
                }
            }

            if (!$keepStill && (count($out[$k]) < 2 || !$moves)) {
                unset($out[$k]);
            }
        }

        $blank = [];

        foreach (array_keys($blankAt) as $i) {
            $blank[] = WogGeometry::fixed($times[$i] ?? 0.0, 4);
        }

        return [
            'tracks' => $out,
            'dur' => $times === [] ? 0.0 : end($times),
            'blank' => $blank,
        ];
    }

    /**
     * Property-tracks into moment-rows.
     *
     * The entity keeps one row per moment with every value on it, while the
     * source gives each property its own times. We merge onto the union of the
     * moments and fill the gaps by sampling — it is linear between points
     * anyway, so this invents no new corners.
     *
     * A row is always complete: whatever the source lacked takes its neutral
     * value from `base`. A gap in a row would show in the inspector as an empty
     * cell, and an empty cell reads as zero — which for a scale is a picture
     * that has vanished.
     *
     * @param array<string, list<array{0: float, 1: float}>> $tracks
     * @param array<string, float>                           $base
     *
     * @return list<array<string, float>>
     */
    public static function keysOf(array $tracks, array $base): array
    {
        $times = [];

        foreach (array_keys($base) as $k) {
            foreach ($tracks[$k] ?? [] as [$t]) {
                $times[] = $t;
            }
        }

        $times = array_values(array_unique($times));
        sort($times);

        $rows = [];

        foreach ($times as $t) {
            $row = ['t' => $t];

            foreach ($base as $k => $neutral) {
                $row[$k] = isset($tracks[$k])
                    ? WogGeometry::fixed(self::sample($tracks[$k], $t), 4)
                    : $neutral;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The value of a track at a moment.
     *
     * Linear between points, and the end values hold outside them: an animation
     * that starts somewhere other than zero must not jump on its first frame.
     *
     * @param list<array{0: float, 1: float}> $track
     */
    public static function sample(array $track, float $t): float
    {
        if ($track === []) {
            return 0.0;
        }

        if ($t <= $track[0][0]) {
            return $track[0][1];
        }

        $last = count($track) - 1;

        if ($t >= $track[$last][0]) {
            return $track[$last][1];
        }

        for ($i = 1; $i <= $last; $i++) {
            if ($t <= $track[$i][0]) {
                [$t0, $v0] = $track[$i - 1];
                [$t1, $v1] = $track[$i];
                $span = $t1 - $t0;

                return $span <= 0 ? $v1 : $v0 + ($v1 - $v0) * (($t - $t0) / $span);
            }
        }

        return $track[$last][1];
    }
}
