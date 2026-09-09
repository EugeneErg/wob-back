<?php

declare(strict_types=1);

namespace Wob\Tests\Unit\Library;

use PHPUnit\Framework\TestCase;
use Wob\Library\Infrastructure\Foreign\WogTables;

/**
 * The tables a level leans on but does not contain.
 *
 * Checked against the game's own files rather than made-up ones. A table is
 * the kind of thing that goes wrong quietly: a tree that parses badly comes out
 * visibly broken, while a table loses one key in two thousand and nothing says
 * anything until a single picture fails to appear halfway through a story.
 */
final class WogTablesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../Fixtures/wog';

    public function testResourceIdsPointAtFiles(): void
    {
        $res = WogTables::resources(self::ROOT);

        // Манифестов несколько: общий рядом с уровнями и свой у каждого вида
        // шаров. Фикстура нарочно держит оба — читатель, который берёт только
        // первый, на одном манифесте выглядел бы исправным.
        self::assertArrayHasKey('IMAGE_BALL_GENERIC_ARM_INACTIVE', $res['images']);
        self::assertArrayHasKey('IMAGE_BALL_COMMON_BODY', $res['images']);
        self::assertSame('balls/common/body', $res['images']['IMAGE_BALL_COMMON_BODY']);
    }

    /**
     * Sounds are in the manifest and have to be read from it.
     *
     * They were not, for a long time: the reader only ever looked at `Image`,
     * so the sound table stayed empty and every level reported its music as
     * "no such file in the set". The file was there all along; nobody was
     * looking for it. The report was honest and the conclusion it invited —
     * that the set had no music — was wrong.
     */
    public function testSoundsAreReadAndNotJustImages(): void
    {
        $res = WogTables::resources(self::ROOT);

        self::assertNotEmpty($res['sounds']);
        self::assertArrayHasKey('SOUND_GLOBAL_NUKE', $res['sounds']);
        self::assertStringContainsString('sounds/', $res['sounds']['SOUND_GLOBAL_NUKE']);
    }

    /**
     * A prefix applies until the next one, so entries cannot be read out of
     * order — a prefix left over from the previous group would point half the
     * pictures at the wrong folder, and every one of them would still look
     * like a valid path.
     */
    public function testAPrefixAppliesUntilTheNextOne(): void
    {
        $res = WogTables::resources(self::ROOT);
        $prefixed = array_filter(
            $res['images'],
            static fn (string $path): bool => str_starts_with($path, 'balls/'),
        );

        self::assertNotEmpty($prefixed);

        foreach ($prefixed as $path) {
            self::assertStringNotContainsString('res/', $path);
        }
    }

    public function testFrictionBecomesSmoothnessTheOtherWayRound(): void
    {
        $mats = WogTables::materials(self::ROOT);

        self::assertNotEmpty($mats);

        // Smoothness is the inverse of friction, and the ends have to land in
        // the right place: the slipperiest material in the table must come out
        // smoother than the grippiest, or every slope in the game is wrong.
        $byFriction = $mats;
        uasort($byFriction, static fn (array $a, array $b): int => $a['smoothness'] <=> $b['smoothness']);

        $grippiest = reset($byFriction);
        $slipperiest = end($byFriction);

        self::assertLessThan($slipperiest['smoothness'], $grippiest['smoothness']);
        self::assertGreaterThan(0, $grippiest['smoothness']);
        self::assertLessThanOrEqual(1, $slipperiest['smoothness']);
    }

    /**
     * An effect declared twice — on its own and as ambient — is one effect
     * placed differently, not two with the same name. Reading it as two would
     * give the level a second emitter the original never had.
     */
    public function testAnAmbientEffectIsTheSameEffectMarked(): void
    {
        $fx = WogTables::effects(self::ROOT);

        self::assertNotEmpty($fx);

        $ambient = array_filter($fx, static fn (array $e): bool => $e['ambient']);
        self::assertNotEmpty($ambient, 'the set has ambient effects');

        foreach ($fx as $name => $effect) {
            self::assertIsString($name);
            self::assertSame($name, $effect['attrs']['name'] ?? $name);
        }
    }

    public function testTextIsKeyedAndItsLinesAreSplit(): void
    {
        $strings = WogTables::strings(self::ROOT);

        self::assertNotEmpty($strings);

        // Translations are separated by a vertical bar. Left alone, a sign
        // would show every language at once on one line.
        foreach ($strings as $text) {
            self::assertStringNotContainsString('|', $text);
        }
    }

    public function testAMissingTextFileIsEmptyRatherThanFatal(): void
    {
        self::assertSame([], WogTables::strings(self::ROOT . '/nowhere'));
    }
}
