<?php

declare(strict_types=1);

namespace Wob\Tests\Unit\Library;

use PHPUnit\Framework\TestCase;
use Wob\Library\Infrastructure\Foreign\WogGeometry;

/**
 * The solid shapes a scene is built from.
 *
 * Numbers here are checked against what the working converter produces, not
 * against what looks plausible. Geometry is the part where "close enough" hides
 * best: a shape half a pixel out still draws, still collides, and only shows up
 * as a structure that will not stand.
 */
final class WogGeometryTest extends TestCase
{
    /** The y flip, done once at the edge so no shape has to remember it. */
    private \Closure $point;

    protected function setUp(): void
    {
        $this->point = static fn (float $x, float $y): array => [round($x, 2), round(100 - $y, 2)];
    }

    public function testARectangleTurnsAboutItsMiddle(): void
    {
        $flat = WogGeometry::rectPoints(0, 0, 10, 4, 0, $this->point);

        self::assertSame([[-5.0, 102.0], [5.0, 102.0], [5.0, 98.0], [-5.0, 98.0]], $flat);

        // A quarter turn swaps the sides. If the rotation were applied about a
        // corner instead, the shape would also move — and a level full of
        // slightly displaced platforms looks like bad physics, not bad maths.
        $turned = WogGeometry::rectPoints(0, 0, 10, 4, M_PI / 2, $this->point);
        $xs = array_column($turned, 0);
        $ys = array_column($turned, 1);

        self::assertEqualsWithDelta(4.0, max($xs) - min($xs), 1e-9);
        self::assertEqualsWithDelta(10.0, max($ys) - min($ys), 1e-9);
        self::assertEqualsWithDelta(0.0, array_sum($xs) / 4, 1e-9);
    }

    public function testACircleIsDrawnWithAsManySidesAsItsSizeDeserves(): void
    {
        // A pebble with forty-eight sides costs the solver what a boulder does
        // and looks no rounder; a boulder with twelve is visibly a nut.
        self::assertCount(12, WogGeometry::circlePoints(0, 0, 8, $this->point));
        self::assertCount(25, WogGeometry::circlePoints(0, 0, 100, $this->point));
        self::assertCount(48, WogGeometry::circlePoints(0, 0, 500, $this->point));

        // Допуск — по округлению координат, а не по идеалу: точки кладутся с
        // точностью до сотой, и требовать от них большего значит проверять
        // округление, а не окружность.
        foreach (WogGeometry::circlePoints(0, 0, 40, $this->point) as [$x, $y]) {
            self::assertEqualsWithDelta(40.0, hypot($x, 100 - $y), 0.01);
        }
    }

    /**
     * A line is a half-plane, and the solid lies behind the normal.
     *
     * My first version spread it across both diagonals of the level and made a
     * sheet covering half the screen — which collides fine and ruins the
     * editor, because a click on empty space lands on a wall.
     */
    public function testALineBecomesAStripBehindItsFace(): void
    {
        // Facing up, so the solid is below: in flipped coordinates, larger y.
        $pts = WogGeometry::linePoints(0, 0, 0, 1, 1000, $this->point);

        self::assertCount(4, $pts);

        $ys = array_column($pts, 1);
        self::assertEqualsWithDelta(100.0, min($ys), 1e-9);
        self::assertEqualsWithDelta(300.0, max($ys), 1e-9);
    }

    public function testAnUnnormalisedNormalStillGivesTheSameWall(): void
    {
        $unit = WogGeometry::linePoints(0, 0, 0, 1, 100, $this->point);
        $long = WogGeometry::linePoints(0, 0, 0, 7, 100, $this->point);

        self::assertSame($unit, $long);
    }

    public function testTagsBecomeSurfacePropertiesAndTheRestAreReported(): void
    {
        $said = [];
        $miss = static function (string $what) use (&$said): void {
            $said[] = $what;
        };

        self::assertSame(['walkable' => false], WogGeometry::surfaceOf('unwalkable', $miss));
        self::assertSame(['deadly' => true], WogGeometry::surfaceOf('geomkiller', $miss));
        self::assertSame([], $said);

        // Почти смертельная — смертельная, щадящая крепких. Раньше она молча
        // становилась просто смертельной и уносила с собой замысел: череп в
        // исходнике помечен неуязвимым и обязан пройти там, где перемалывает
        // обычных.
        self::assertSame(
            ['deadly' => true, 'sparesTough' => true],
            WogGeometry::surfaceOf('mostlydeadly', $miss),
        );

        // Лопающая поверхность и порог разрушения тоже стали свойствами.
        self::assertSame(['bursting' => true], WogGeometry::surfaceOf('ballbuster', $miss));
        self::assertSame(['breakForce' => 2.0], WogGeometry::surfaceOf('break=2', $miss));
        self::assertSame(['sticky' => 1800.0], WogGeometry::surfaceOf('kindasticky', $miss));
        self::assertSame([], $said, 'ни один из них больше не уходит в отчёт');

        // А тег, которого никто не знает, по-прежнему всплывает, а не тонет в
        // ветке «иначе ничего».
        WogGeometry::surfaceOf('нетакого', $miss);
        self::assertStringContainsString('нетакого', $said[0]);
    }

    public function testSeveralTagsAllApply(): void
    {
        $miss = static fn (string $what): null => null;

        self::assertSame(
            ['walkable' => false, 'deadly' => true],
            WogGeometry::surfaceOf('unwalkable, deadly', $miss),
        );
        self::assertSame([], WogGeometry::surfaceOf(null, $miss));
    }
}
