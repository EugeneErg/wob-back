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
     * Размер части считается от СВОЕЙ картинки, а не от шара.
     *
     * Чужой `scale` умножает натуральный размер картинки, наш — ширину шара.
     * Величины разные, и пропустить одну за другую нельзя: у common тело 64 px
     * при радиусе 15, и доля шара выходит 0.55 вместо 1.17 — тело съёживается
     * вдвое. Глаз при этом 32 px, и его доля совпадает почти точно СЛУЧАЙНО,
     * поэтому глаза остаются прежними, а тело оказывается с них размером.
     * Шар выглядит прозрачным, и ничто, кроме взгляда на него, об этом не
     * скажет: чисел не меняется ни одно, все проверки молчат.
     *
     * Проверяется тут не само число, а то, из чего оно получено: ширина на
     * экране обязана равняться натуральной ширине картинки, помноженной на
     * чужой scale. Две части с РАЗНЫМИ картинками — иначе ошибка «делим на
     * ширину шара» прошла бы мимо на любой одной.
     */
    public function testAPartIsSizedFromItsOwnPictureAndNotFromTheBall(): void
    {
        $ball = $this->build('common');
        $width = 2 * $ball['r'];

        $by = [];

        foreach ($ball['parts'] as $part) {
            $by[$part['name']] ??= $part;
        }

        // body.png — 64×64, scale в наборе 0.549843
        self::assertSame('balls/common/body.png', $by['body']['src']);
        self::assertEqualsWithDelta(64 * 0.549843, $width * $by['body']['scale'], 0.01);

        // eye_glass_1.png — 32×32, scale 0.5. Картинка вдвое мельче тела, и
        // доля шара обязана выйти другой.
        self::assertSame('balls/_generic/eye_glass_1.png', $by['lefteye']['src']);
        self::assertEqualsWithDelta(32 * 0.5, $width * $by['lefteye']['scale'], 0.01);

        self::assertNotSame($by['body']['scale'], $by['lefteye']['scale']);
    }

    /**
     * Зрачок меряется своей картинкой и ходит внутри глаза.
     *
     * Размера зрачка в наборе НЕТ — там сказано только, какая это картинка и
     * каков `pupilinset`. Раньше размер брался выдуманным числом 0.3, то есть
     * 9 px при глазе 16: зрачок занимал больше половины глаза, сливался с
     * чёрным телом, и шар читался как «тело размером с глаза».
     *
     * `pupilinset` же меряется натуральными пикселями САМОГО ГЛАЗА, а не шара:
     * глаз 32 px, отступ 12 — зрачок ходит на 4 px от середины, то есть 2 px
     * на экране. Поделив отступ на радиус шара, мы получали 0.8, и ход
     * выходил отрицательным — зрачок замирал в середине намертво, и глаза
     * переставали быть бегающими.
     */
    public function testThePupilIsSizedByItsOwnPictureAndCanMoveInsideTheEye(): void
    {
        $ball = $this->build('common');
        $eye = null;

        foreach ($ball['parts'] as $part) {
            if ($part['name'] === 'lefteye') {
                $eye = $part;

                break;
            }
        }

        self::assertNotNull($eye);

        $width = 2 * $ball['r'];
        // pupil1.png — 8×8, scale глаза 0.5.
        self::assertEqualsWithDelta(8 * 0.5, $width * $eye['pupilSize'], 0.01);
        // Зрачок — четверть глаза, а не половина с лишним.
        self::assertLessThan(0.35, $eye['pupilSize'] / $eye['scale']);

        // Ход зрачка: половина глаза минус отступ, и он обязан быть больше нуля.
        $reach = $width * $eye['scale'] / 2 - $eye['pupilInset'] * $ball['r'];
        self::assertGreaterThan(0.0, $reach);
        self::assertEqualsWithDelta((32 / 2 - 12) * 0.5, $reach, 0.01);
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
    /**
     * The player's hand: what may be picked up, what may be pulled out, and how
     * far it has to be pulled.
     *
     * `common` has a detachstrand and still says detachable="false" — a built
     * ball stands. Getting this backwards let every structure in the set be
     * taken apart and rebuilt, which is not the game.
     */
    public function testAnOrdinaryBallStaysWhereItWasBuilt(): void
    {
        $ball = $this->build('common');

        self::assertFalse($ball['detachable']);
        self::assertTrue($ball['draggable']);
        self::assertTrue($ball['climber']);
        self::assertSame(0.0, $ball['angle']);
    }

    public function testADetachableBallComesOutOnlyWhenPulledFarEnough(): void
    {
        $ball = $this->fromXml('<ball name="x" shape="circle,30" mass="30" strands="3" detachable="true">'
            . '<strand type="spring" minlen="110" maxlen2="140" shrinklen="130" springconstmax="9" springconstmin="4.5" />'
            . '<detachstrand maxlen="60" /></ball>');

        self::assertTrue($ball['detachable']);
        self::assertSame(60.0, $ball['detachDist']);
        // minlen is a floor and shrinklen a ceiling — not one length every
        // strand is pulled to.
        self::assertSame(110.0, $ball['linkMin']);
        self::assertSame(130.0, $ball['linkMax']);
        self::assertSame(1600.0, $ball['linkSpring']);
        self::assertSame(800.0, $ball['linkSpringFar']);
    }

    public function testWithoutADetachstrandNothingComesOut(): void
    {
        // The format calls the tag required for detaching.
        $ball = $this->fromXml('<ball name="x" shape="circle,30" mass="30" strands="2" detachable="true">'
            . '<strand type="spring" minlen="100" maxlen2="140" /></ball>');

        self::assertFalse($ball['detachable']);
        // shrinklen has a default of its own, 140.
        self::assertSame(140.0, $ball['linkMax']);
    }

    public function testABallWithNoStrandsNeverJoinsAStructure(): void
    {
        $ball = $this->fromXml('<ball name="x" shape="circle,30" mass="30" strands="0" draggable="false" climber="false" />');

        self::assertSame(0, $ball['maxLinks']);
        self::assertFalse($ball['draggable']);
        self::assertFalse($ball['climber']);
    }

    public function testABurningStrandCarriesItsSpeedAndDelay(): void
    {
        $ball = $this->fromXml('<ball name="x" shape="circle,30" mass="30" strands="2" burntime="3">'
            . '<strand type="spring" maxlen2="140" ignitedelay="0.5" burnspeed="2" fireparticles="fuseBurn" /></ball>');

        // burnspeed counts per tick, and a tick is 1/50 — the same conversion
        // the climb and walk speeds use.
        self::assertSame(100.0, $ball['fireSpeed']);
        self::assertSame(0.5, $ball['fireDelay']);
        self::assertTrue($ball['fireLinks']);
    }

    public function testAStrandWithoutFireParticlesDoesNotBurn(): void
    {
        // The format calls fireparticles required for a strand to burn at all.
        $ball = $this->fromXml('<ball name="x" shape="circle,30" mass="30" strands="2" burntime="3">'
            . '<strand type="spring" maxlen2="140" burnspeed="2" /></ball>');

        self::assertFalse($ball['fireLinks']);
    }

    /**
     * Two different questions about pushing other balls, and they are not the
     * same one twice.
     */
    public function testTheTwoCollisionFlagsStaySeparate(): void
    {
        $block = $this->fromXml('<ball name="x" shape="rectangle,100,100" mass="30" strands="0" '
            . 'speedvariance="0.2" collidewithattached="true" collideattached="true" />');
        $floater = $this->fromXml('<ball name="y" shape="circle,30" mass="30" strands="1" '
            . 'collideattached="true" />');

        self::assertTrue($block['hitsBuilt']);
        self::assertTrue($block['builtHitsBuilt']);
        self::assertSame(0.2, $block['speedSpread']);

        // A balloon pushes its neighbours apart while it hangs in a structure,
        // yet flies through somebody else's.
        self::assertFalse($floater['hitsBuilt']);
        self::assertTrue($floater['builtHitsBuilt']);
    }

    public function testAnOrdinaryBallPushesNoOtherBall(): void
    {
        $ball = $this->build('common');

        self::assertFalse($ball['hitsBuilt']);
        self::assertFalse($ball['builtHitsBuilt']);
        self::assertSame(0.2, $ball['speedSpread']);
    }

    public function testBlocksStackFreezeAndTurnInTheHand(): void
    {
        $block = $this->fromXml('<ball name="x" shape="rectangle,100,100" mass="600" strands="0" '
            . 'stacking="true" autodisable="true" hingedrag="true" dragmass="100" />');

        // Вес в руке вшестеро меньше собственного: рука тянет шар пружиной, и
        // без облегчения глыбу было бы не поворочать.
        self::assertSame(20.0, $block['mass']);
        self::assertSame(3.333, $block['dragMass']);
        self::assertTrue($block['stacks']);
        self::assertTrue($block['freezeWhenStill']);
        self::assertTrue($block['hingeDrag']);
    }

    public function testAnOrdinaryBallDoesNoneOfThat(): void
    {
        $ball = $this->build('common');

        self::assertFalse($ball['stacks']);
        self::assertFalse($ball['freezeWhenStill']);
        self::assertFalse($ball['hingeDrag']);
    }

    public function testTheLaunchArrowCarriesItsLengthAndForce(): void
    {
        // Bit and Pilot both say fling="200,2.5": the arrow grows to 200, and
        // the multiplier turns its length into a force, so a lighter ball goes
        // further. The push is stored for a full draw; the engine divides it by
        // the ball's mass.
        $bit = $this->fromXml('<ball name="x" shape="circle,30" mass="30" strands="2" fling="200,2.5" />');

        self::assertSame(200.0, $bit['flingRange']);
        self::assertSame(950.0, $bit['flingPush']);
    }

    public function testABallWithoutFlingIsJustCarried(): void
    {
        $ball = $this->build('common');

        self::assertSame(0.0, $ball['flingRange']);
        self::assertSame(0.0, $ball['flingPush']);
    }

    public function testASleeperHangsWhereItWasPutAndJumpsAwake(): void
    {
        $ball = $this->fromXml('<ball name="x" shape="circle,30" mass="30" strands="2" '
            . 'staticwhensleeping="true" jumponwakeup="true" />');

        self::assertSame('place', $ball['sleepHold']);
        self::assertTrue($ball['wakeJump']);
    }

    /**
     * Спящий держится на месте, даже если у вида нет признака про это.
     *
     * Опровергает обратное сам набор: в `BulletinBoardSystem` вся левая
     * постройка — двадцать два спящих `Pixel` без такого признака, и висит она
     * в боковом тяготении без единой опоры.
     */
    public function testEveryKindHoldsStillWhileAsleep(): void
    {
        self::assertSame('place', $this->build('common')['sleepHold']);
        self::assertSame('place', $this->build('Pixel')['sleepHold']);
        self::assertFalse($this->build('common')['wakeJump']);
    }

    public function testEyesBlinkInTheGivenColourAndMayFollowTheMouse(): void
    {
        $ball = $this->fromXml('<ball name="x" shape="circle,30" mass="30" strands="2" '
            . 'blinkcolor="0,255,0" alwayslookatmouse="true" />');

        self::assertSame('#00ff00', $ball['blinkColor']);
        self::assertTrue($ball['eyesFollowPointer']);
    }

    public function testAColourWrittenWrongIsLeftOutRatherThanGuessed(): void
    {
        // Пропущенный цвет лучше выдуманного: шар просто не моргает.
        $ball = $this->fromXml('<ball name="x" shape="circle,30" mass="30" strands="2" blinkcolor="чепуха" />');

        self::assertSame('', $ball['blinkColor']);
    }

    public function testWhatMakesABallStickAndWhereItIsDrawn(): void
    {
        $ball = $this->fromXml('<ball name="x" shape="circle,30" mass="30" strands="1" '
            . 'maxattachspeed="1000" autoattach="true" isbehindstrands="true" />');

        self::assertSame(1000.0, $ball['attachSpeed']);
        self::assertTrue($ball['autoAttach']);
        self::assertTrue($ball['behindLinks']);
    }

    /** @return array<string, mixed> */
    private function fromXml(string $xml): array
    {
        $res = WogTables::resources(self::ROOT);

        return WogBallKind::build(WogFile::parseXml($xml), $res['images'], $res['sounds'], self::ROOT);
    }

    private function build(string $type): array
    {
        $def = WogFile::parseXml(WogFile::decrypt(
            (string) file_get_contents(self::ROOT . "/balls/{$type}/balls.xml.bin"),
        ));
        $res = WogTables::resources(self::ROOT);

        return WogBallKind::build($def, $res['images'], $res['sounds'], self::ROOT);
    }
}
