<?php

declare(strict_types=1);

namespace Wob\Tests\Unit\Library;

use PHPUnit\Framework\TestCase;
use Wob\Library\Infrastructure\Foreign\WogBallKind;
use Wob\Library\Infrastructure\Foreign\WogFile;
use Wob\Library\Infrastructure\Foreign\WogTables;

/**
 * A ball type, built from the game's own definition of one.
 *
 * The fixture is `common` — the ordinary grey ball the whole unit conversion is
 * pinned to. That makes it the right thing to check against: if its numbers
 * come out wrong, every other type is wrong by the same factor and the mistake
 * hides behind a set of levels that merely feel heavy.
 */
final class WogBallKindTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../Fixtures/wog';

    public function testTheAnchorTypeConvertsToTheEngineDefaults(): void
    {
        $ball = $this->build('common');

        // common is the anchor: mass 30 there is 1 here, and the rest of the
        // scale factors follow from it. Mass matters most — it sets the load a
        // strand works under, and without the conversion a structure comes out
        // thirty times heavier than the stiffness was chosen for.
        self::assertSame(1.0, $ball['mass']);
        self::assertSame(1600.0, $ball['linkSpring']);
        // 43333, а не 26000: множитель 26000/600, а у common maxforce="1000".
        // Я написал сюда 26000, поверив комментарию рядом с формулой, и
        // проверка поймала не перенос, а тот комментарий — он называл 600
        // значением опоры, тогда как это умолчание для шара без связки.
        self::assertSame(43333.0, $ball['linkBreak']);
        self::assertSame(95.0, $ball['climbSpeed']);
        self::assertSame(1400.0, $ball['walkForce']);
    }

    public function testTheBodyIsARoundBallOfTheStatedSize(): void
    {
        $ball = $this->build('common');

        // shape gives a diameter, not a radius.
        self::assertSame(15.0, $ball['r']);
    }

    public function testPartsCarryTheirPicturesAndPoses(): void
    {
        $ball = $this->build('common');

        self::assertNotEmpty($ball['parts']);

        $body = null;

        foreach ($ball['parts'] as $part) {
            if ($part['name'] === 'body') {
                $body = $part;
                break;
            }
        }

        self::assertNotNull($body);
        self::assertStringEndsWith('.png', $body['src']);

        // Offsets are in radii, not pixels: a ball changes size between its
        // profiles and its parts have to travel with it rather than come loose.
        self::assertIsFloat($body['dx']);
        self::assertLessThan(5, abs($body['dx']));
    }

    /**
     * A part with no state list is visible always, and one with a list is
     * visible only in those poses. Two thirds of the parts in the set have no
     * list at all, so getting the empty case backwards would hide most of
     * every ball.
     */
    public function testAPartWithoutStatesIsVisibleEverywhere(): void
    {
        $ball = $this->build('common');
        $always = array_filter($ball['parts'], static fn (array $p): bool => $p['poses'] === []);

        self::assertNotEmpty($always);
    }

    /**
     * Waves keep their group, and the group is the point.
     *
     * Variance wraps a set of sines rather than sitting on each one. A walk
     * moves the body and both eyes together; if each drew its own random phase
     * the walk would come apart, and nothing but looking at it would say so.
     */
    public function testWavesKeepTheGroupTheirVarianceWraps(): void
    {
        $ball = $this->build('common');

        self::assertNotEmpty($ball['waves']);

        $byGroup = [];

        foreach ($ball['waves'] as $wave) {
            self::assertGreaterThan(0, $wave['group']);
            $byGroup[$wave['group']][] = $wave;
        }

        $shared = array_filter($byGroup, static fn (array $g): bool => count($g) > 1);
        self::assertNotEmpty($shared, 'at least one group holds several sines');

        // Every sine in a group carries the same variance, because the variance
        // belongs to the group.
        foreach ($shared as $group) {
            foreach ($group as $wave) {
                self::assertSame($group[0]['vFreq'], $wave['vFreq']);
                self::assertSame($group[0]['vShift'], $wave['vShift']);
            }
        }
    }

    public function testTranslationAmplitudeIsConvertedFromPixels(): void
    {
        $ball = $this->build('common');

        foreach ($ball['waves'] as $wave) {
            // Scale amplitude is a factor and stays as written; translation is
            // pixels there and radii here, so it must have been divided down.
            if ($wave['prop'] === 'dx' || $wave['prop'] === 'dy') {
                self::assertLessThan(5, abs($wave['amp']));
            }
        }
    }

    public function testEveryFieldTheEngineWantsIsFilledIn(): void
    {
        $ball = $this->build('common');

        // A saved level has to be complete: a field that arrives from an engine
        // default changes when somebody edits the engine, silently and without
        // the author's consent. So nothing here may be missing.
        foreach (['x', 'y', 'r', 'mass', 'parts', 'waves', 'sounds', 'sizes', 'suckable', 'links'] as $key) {
            self::assertArrayHasKey($key, $ball);
        }

        self::assertNotContains(null, $ball, 'no field is left unset');
    }

    /** @return array<string, mixed> */
    private function build(string $type): array
    {
        $def = WogFile::parseXml(WogFile::decrypt(
            (string) file_get_contents(self::ROOT . "/balls/{$type}/balls.xml.bin"),
        ));
        $res = WogTables::resources(self::ROOT);

        return WogBallKind::build($def, $res['images'], $res['sounds'], self::ROOT);
    }
}
