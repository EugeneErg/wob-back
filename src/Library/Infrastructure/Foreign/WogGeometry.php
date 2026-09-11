<?php

declare(strict_types=1);

namespace Wob\Library\Infrastructure\Foreign;

/**
 * The solid shapes a scene is built from.
 *
 * Lines, rectangles, circles and rigid assemblies of them. Everything here
 * turns into either `terrain` (fixed) or `object` (movable), and the choice is
 * the original's own `static` flag, not a guess about what looks immovable.
 *
 * Coordinates are handed in already flipped: the original's y axis points up
 * and ours points down, and doing that conversion in one place, at the edge, is
 * what keeps every shape from having to remember it.
 */
final class WogGeometry
{
    /**
     * How far a wall reaches behind its own face.
     *
     * A line is a half-plane: the solid lies on the far side of the normal. My
     * first version spread it across both diagonals of the level and produced a
     * sheet covering half the screen. A strip is enough — a ball travels single
     * pixels per substep — and it leaves the canvas free, so a click on empty
     * space in the editor does not land on a wall.
     */
    private const WALL = 200;

    /**
     * Rounding that matches JavaScript's toFixed exactly.
     *
     * Three ways to round disagree here, and each disagrees differently:
     *
     *   round()  nudges the value first, so 324.025 comes out 324.03 even
     *            though the double actually holds 324.02499999999997;
     *   sprintf  goes by the true binary value but rounds a half to the even
     *            digit, so an exact 476.625 comes out 476.62;
     *   toFixed  goes by the true binary value and rounds a half away from
     *            zero: 324.02 and 476.63.
     *
     * A hundredth on one point of one platform is enough to make a content
     * hash differ, so the port has to agree with the original digit for digit.
     * We take the exact decimal expansion of the double and round it by hand.
     */
    /**
     * Rounding to a whole number, the way Math.round does it.
     *
     * They part company on negative halves: Math.round takes the half UP, so
     * −2.5 becomes −2, while PHP takes it away from zero and gives −3. Layer
     * depths land on halves often enough that this showed up as a picture one
     * step behind where it belonged.
     */
    public static function whole(float $v): int
    {
        return (int) floor($v + 0.5);
    }

    public static function fixed(float $v, int $places = 2): float
    {
        // Twenty digits is past the point where a double holds any information,
        // so this is the true value and not a rounded view of it.
        $exact = sprintf('%.20F', $v);
        $dot = strpos($exact, '.');

        if ($dot === false) {
            return $v;
        }

        $keep = substr($exact, 0, $dot + $places + 1);
        $next = $exact[$dot + $places + 1] ?? '0';

        $out = (float) $keep;

        if ($next >= '5') {
            $step = 10 ** -$places;
            $out = $v < 0 ? $out - $step : $out + $step;
        }

        return (float) sprintf('%.' . $places . 'F', $out);
    }

    /**
     * Tags that became surface properties, and the ones that did not.
     *
     * Both lists are spelled out on purpose. A tag nobody knows about has to
     * surface in the report rather than vanish into an "otherwise nothing"
     * branch — that is how a level quietly loses the thing that made it work.
     *
     * @param callable(string): void $miss
     *
     * @return array<string, bool|float>
     */
    public static function surfaceOf(?string $tags, callable $miss): array
    {
        $out = [];

        foreach (array_filter(array_map('trim', explode(',', $tags ?? ''))) as $tag) {
            match ($tag) {
                'unwalkable' => $out['walkable'] = false,
                'walkable' => $out['walkable'] = true,
                'detaching' => $out['detaching'] = true,
                // Лопающая поверхность. По описанию формата шар с начинкой
                // лопается именно от касания такой — это парная половина к
                // содержимому, а не разновидность смертельности.
                'ballbuster' => $out['bursting'] = true,
                // Липкая поверхность. Величины в исходнике нет — только сам
                // признак, — поэтому названа она здесь: 1800 это тяготение
                // уровня в наборе, а удержать коснувшегося значит перебить
                // именно его. Ровно столько, а не с запасом: липкая стена
                // должна держать, но не хватать намертво пролетающих мимо.
                'kindasticky' => $out['sticky'] = 1800.0,
                'stopsign' => $out['stopsign'] = true,
                'deadly', 'geomkiller' => $out['deadly'] = true,
                // Почти смертельная — смертельная, которая щадит крепких.
                //
                // Раньше она становилась просто смертельной, и это уносило с
                // собой замысел: череп в исходнике помечен неуязвимым и обязан
                // пройти там, где перемалывает обычных. Без разницы между
                // «губит всех» и «губит всех, кроме крепких» такой уровень
                // становится непроходимым — причём тихо, потому что сама
                // поверхность работает.
                'mostlydeadly' => (static function () use (&$out): void {
                    $out['deadly'] = true;
                    $out['sparesTough'] = true;
                })(),
                // Порог разрушения: «break=2» значит, что тело разлетится от
                // взрыва силой два и выше. Мерится в тех же единицах, что сила
                // взрыва у шаров, и сравнивается с ней напрямую.
                default => str_starts_with($tag, 'break=')
                    ? $out['breakForce'] = (float) substr($tag, 6)
                    : $miss("тег геометрии «{$tag}»"),
            };
        }

        return $out;
    }

    /**
     * The four corners of a rectangle, turned about its middle.
     *
     * @param callable(float, float): array{0: float, 1: float} $point
     *
     * @return list<array{0: float, 1: float}>
     */
    /**
     * Середина очертания — среднее по вершинам.
     *
     * Не центр тяжести: для оси вращения хватает и середины, а честный центр
     * тяжести многоугольника потребовал бы разбиения на треугольники ради
     * разницы, которой на выпуклых шестернях набора нет.
     *
     * @param list<array{0: float, 1: float}> $pts
     *
     * @return array{0: float, 1: float}
     */
    public static function middleOf(array $pts): array
    {
        if ($pts === []) {
            return [0.0, 0.0];
        }

        $x = 0.0;
        $y = 0.0;

        foreach ($pts as [$px, $py]) {
            $x += $px;
            $y += $py;
        }

        return [self::fixed($x / count($pts), 2), self::fixed($y / count($pts), 2)];
    }

    /** @return list<array{0: float, 1: float}> */
    /**
     * Охватывающая рамка очертания.
     *
     * @param list<array{0: float, 1: float}> $pts
     *
     * @return array{w: float, h: float}
     */
    public static function boxOf(array $pts): array
    {
        if ($pts === []) {
            return ['w' => 0.0, 'h' => 0.0];
        }

        $xs = array_column($pts, 0);
        $ys = array_column($pts, 1);

        return ['w' => max($xs) - min($xs), 'h' => max($ys) - min($ys)];
    }

    /** @return list<array{0: float, 1: float}> */
    public static function rectPoints(
        float $x,
        float $y,
        float $w,
        float $h,
        float $rot,
        callable $point,
    ): array {
        $cos = cos($rot);
        $sin = sin($rot);
        $out = [];

        foreach ([[-$w / 2, -$h / 2], [$w / 2, -$h / 2], [$w / 2, $h / 2], [-$w / 2, $h / 2]] as [$dx, $dy]) {
            $out[] = $point($x + $dx * $cos - $dy * $sin, $y + $dx * $sin + $dy * $cos);
        }

        return $out;
    }

    /**
     * A circle as a polygon.
     *
     * The segment count follows the radius rather than being fixed: a pebble
     * drawn with forty-eight sides costs the solver the same as a boulder and
     * looks no rounder, while a boulder drawn with twelve is visibly a nut.
     *
     * @param callable(float, float): array{0: float, 1: float} $point
     *
     * @return list<array{0: float, 1: float}>
     */
    public static function circlePoints(float $x, float $y, float $r, callable $point): array
    {
        $seg = max(12, min(48, (int) round($r / 4)));
        $out = [];

        for ($i = 0; $i < $seg; $i++) {
            $a = ($i / $seg) * M_PI * 2;
            $out[] = $point($x + cos($a) * $r, $y + sin($a) * $r);
        }

        return $out;
    }

    /**
     * The strip standing in for a half-plane.
     *
     * Not one of the 140 lines in the reference set has a picture: these are
     * invisible walls. Terrain with default colours drew them green — showing
     * the player exactly what they were not meant to see — so the caller marks
     * them invisible while they keep their tags, because the bottom edge of
     * many levels is tagged deadly and that has to survive.
     *
     * @param callable(float, float): array{0: float, 1: float} $point
     *
     * @return list<array{0: float, 1: float}>
     */
    public static function linePoints(
        float $ax,
        float $ay,
        float $nx,
        float $ny,
        float $reach,
        callable $point,
    ): array {
        $len = hypot($nx, $ny) ?: 1.0;
        $nx /= $len;
        $ny /= $len;
        $tx = -$ny;
        $ty = $nx;

        return [
            $point($ax + $tx * $reach, $ay + $ty * $reach),
            $point($ax - $tx * $reach, $ay - $ty * $reach),
            $point($ax - $tx * $reach - $nx * self::WALL, $ay - $ty * $reach - $ny * self::WALL),
            $point($ax + $tx * $reach - $nx * self::WALL, $ay + $ty * $reach - $ny * self::WALL),
        ];
    }
}
