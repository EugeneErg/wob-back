<?php

declare(strict_types=1);

namespace Wob\Library\Infrastructure\Foreign;

/**
 * One level of the original game, turned into ours.
 *
 * A level is described by two files: `level` says what is in it — balls, pipe,
 * signs, camera — and `scene` says what it is built of: geometry, decoration,
 * particles, force fields. Both are read here and become one flat list of
 * entities.
 *
 * Nothing asks the engine what a field is called or what it defaults to. Every
 * value is named here on purpose, because a saved level has to be complete: a
 * field that arrives from a default changes the day somebody edits the engine,
 * silently and without the author's consent. `tests/importfields.mjs` on the
 * other side keeps the two in step.
 */
final class WogLevel
{
    /** Force 10 in the original is 1800 px/s² here. */
    private const G = 180;

    /** Particle speeds are per frame there and per second here. */
    private const V = 60;
    private const A = 3600;

    /**
     * Layer depths, named rather than imported, for the same reason as the rest.
     *
     * OVERLAY is what the player drags, and a sign panel sits just under it —
     * over everything in the world, below the thing in hand. That was written
     * as a bare 65 and so depended on the engine's scale without saying so: if
     * overlay moved, the panel would end up above the ball being carried and
     * nothing would say a word. The client checks these three against its own
     * scale, which is only possible because they have names.
     */
    private const BACKGROUND = -60;
    private const MIDGROUND = 0;
    private const OVERLAY = 70;

    /**
     * The widescreen camera's reckoning screen.
     *
     * Zoom says how much the world is magnified, not how big the window is, so
     * the visible part is this divided by the zoom. Checked against a dozen
     * levels: 1280 over endzoom matches the scene width to within a percent.
     */
    private const WIDE_W = 1280;
    private const WIDE_H = 720;

    private int $n = 0;

    /** @var list<array<string, mixed>> */
    private array $entities = [];

    /** @var array<string, string> resource id to file */
    private array $media = [];

    /** @var array<string, array{id: string, static: bool, at: int}> */
    private array $byGeomId = [];

    private float $minx = 0;
    private float $maxy = 0;
    private float $w = 0;
    private float $h = 0;

    /** @var array{x: float, y: float} */
    private array $gravity = ['x' => 0.0, 'y' => 1800.0];

    /** @var list<float> */
    private array $depths = [];

    /**
     * Начинка по видам шаров: что выйдет наружу, когда такой шар погибнет.
     *
     * @var array<string, list<array{count: int, asset: string}>>
     */
    private array $fillings = [];

    /**
     * Какие виды принимает труба, или null, если принимает всех.
     *
     * @var array<string, true>|null
     */
    private ?array $takes = null;

    /**
     * Где стоит тело с таким именем и каким оно вышло по счёту.
     *
     * @var array<string, array{x: float, y: float, at: int, w: float, h: float, mass: float, still: bool}>
     */
    private array $geomMiddle = [];

    /**
     * Что раздают раздатчики: материал → true. Замок у кнопки ждёт это.
     *
     * @var array<string, true>
     */
    private array $handed = [];

    /**
     * Поставить кнопку и замок над ней.
     *
     * Замок здесь не украшение: в исходнике до иконки надо дотянуться башней,
     * и щелчок работает, только когда блок рядом. Это два разных вопроса, и
     * задаёт их каждый своей сущности — замок «дотянулся ли», кнопка «щёлкнул
     * ли». Сводить их в одно поле «И» значило бы заводить язык условий там,
     * где хватает двух слов на шине.
     *
     * @param array<string, string> $a
     */
    private function placeButton(array $a): void
    {
        $up = $this->imageOf($a['up'] ?? '');

        if ($up === null) {
            $this->say('кнопка без картинки');

            return;
        }

        $x = WogGeometry::fixed($this->x($this->num($a, 'x', 0)), 2);
        $y = WogGeometry::fixed($this->y($this->num($a, 'y', 0)), 2);
        $w = WogGeometry::fixed($up['w'] * $this->num($a, 'scalex', 1), 2);
        $h = WogGeometry::fixed($up['h'] * $this->num($a, 'scaley', 1), 2);

        // Ждём то, что раздают в этом уровне. Раздатчиков нет — ждать нечего,
        // и кнопка работает сразу.
        $wants = array_key_first($this->handed);
        $gate = '';

        if ($wants !== null) {
            $gate = 'reach-' . $this->id('sg');
            // Область шире кнопки: дотянуться значит поднести блок к иконке, а
            // не попасть в неё в точности.
            $this->entities[] = ['id' => $this->id('lck'), 'type' => 'lock', 'data' => [
                'x' => $x, 'y' => $y,
                'w' => WogGeometry::fixed($w * 2.5, 2), 'h' => WogGeometry::fixed($h * 2.5, 2),
                'key' => $wants, 'invert' => false, 'delay' => 0.0,
                'signal' => $gate, 'counts' => false, 'show' => false, 'color' => '#c9a227',
            ]];
        }

        $this->entities[] = ['id' => $this->id('btn'), 'type' => 'button', 'data' => [
            'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'up' => $up['src'],
            'over' => $this->imageOf($a['over'] ?? '')['src'] ?? '',
            'enabledBy' => $gate,
            // Команда не переносится: `onclick` называет зашитое в игру. Имя
            // остаётся сигналом — видно, что нажатие куда-то ведёт.
            'signal' => (string) ($a['onclick'] ?? ''),
            // Защёлка: разговор начался, назад его не забрать.
            'latch' => true,
            'counts' => true,
            'depth' => $this->layerOf($a['depth'] ?? null),
        ]];
        $this->goalFromLocks++;
    }

    /** Сколько шагов к цели дают расставленные замки. */
    private int $goalFromLocks = 0;

    /**
     * Вид каждого размещённого шара: id размещения → имя вида.
     *
     * @var array<string, string>
     */
    private array $kindOf = [];

    /** Сопротивление среды на весь уровень, 1/с. */
    private float $airDrag = 0.0;

    /** @var array<string, array<string, mixed>> types built along the way */
    private array $kinds = [];

    /**
     * @param array<string, mixed>                $scene
     * @param array<string, mixed>                $level
     * @param array{images: array<string, string>, sounds: array<string, string>} $res
     * @param array<string, array<string, mixed>> $mats
     * @param array<string, array<string, mixed>> $fx
     * @param array<string, string>               $strings
     * @param \Closure(string, int): void          $miss
     */
    public function __construct(
        private readonly string $name,
        private readonly array $scene,
        private readonly array $level,
        private readonly array $res,
        private readonly string $root,
        private readonly array $mats,
        private readonly array $fx,
        private readonly array $strings,
        /** @var array<string, array<string, mixed>> */
        private readonly array $ballDefs,
        private readonly \Closure $miss,
    ) {
    }

    /**
     * @return array{level: array<string, mixed>, media: array<string, string>, kinds: array<string, array<string, mixed>>, fillings: array<string, list<array{count: int, asset: string}>>}
     */
    public function convert(): array
    {
        $this->bounds();
        $this->readGravity();
        // Порядок тот же, что на другой стороне, и это не придирка: имена
        // сущностей раздаются счётчиком по мере создания, а связи шаров
        // ссылаются по именам. Переставь местами два раздела — и пакет станет
        // другим документом при том же содержимом.
        $this->geometry();
        $this->decoration();
        $this->particles();
        $this->balls();
        $this->music();
        $this->fires();
        $this->pipeAndExit();
        $this->hinges();
        $this->fields();
        $this->camera();
        $this->sweepMedia();

        return [
            'level' => [
                'id' => 'wog-' . mb_strtolower($this->name),
                'name' => $this->name,
                'width' => WogGeometry::whole($this->w),
                'height' => WogGeometry::whole($this->h),
                'gravity' => $this->gravity,
                // Цель. Обычно это «собрать столько-то в трубу», но у уровня
                // без трубы число из исходника бессмысленно: у `Deliverance`
                // там стоит шесть, а собирать некуда. Тогда целью становятся
                // замки — по шагу на каждый.
                'goal' => $this->goalFromLocks > 0 && WogFile::kids($this->level, 'levelexit') === []
                    ? $this->goalFromLocks
                    : (int) $this->num($this->level['attrs'], 'ballsrequired', 1),
                'hot' => [],
                'entities' => $this->entities,
            ],
            'media' => $this->media,
            'kinds' => $this->kinds,
            'fillings' => $this->fillings,
        ];
    }

    /**
     * Every file the built level mentions, whether or not somebody remembered
     * to write it down.
     *
     * Each place that puts a picture into a level also used to add it to the
     * upload list by hand, and that held for as long as pictures were the only
     * files and every one of them went through the same two or three methods.
     * It stopped holding the moment a ball grew a pupil, a strand picture and
     * two dozen sounds: those live in the ball's own data, the list was only
     * fed from `parts[].src`, and sixty-three files went missing — silently,
     * because a missing upload does not fail anything until a player opens the
     * level and finds a blank where a ball should be.
     *
     * So the list is no longer assembled by remembering. Everything the level
     * and its ball kinds hold is walked through, and every string that names a
     * file which actually exists in the source folder is an upload. A string
     * that merely looks like a path but is not a file is not one, so the test
     * is the file system rather than the spelling.
     */
    private function sweepMedia(): void
    {
        $this->sweep($this->entities);
        $this->sweep($this->kinds);
    }

    private function sweep(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->sweep($item);
            }

            return;
        }

        if (!is_string($value) || $value === '') {
            return;
        }

        if (preg_match('/\.(png|jpg|jpeg|webp|gif|ogg|mp3|wav|mp4|webm)$/i', $value) !== 1) {
            return;
        }

        if (!is_file($this->root . '/' . $value)) {
            return;
        }

        $this->media[$value] = $value;
    }

    /**
     * The room the level lives in.
     *
     * Three levels in the set give no bounds at all, so they are worked out
     * from what is in them. Guessing them as a fixed size would put those three
     * levels' contents outside the world.
     */
    private function bounds(): void
    {
        $s = $this->scene['attrs'];
        $minx = $this->numOrNull($s, 'minx');
        $maxx = $this->numOrNull($s, 'maxx');
        $miny = $this->num($s, 'miny', 0);
        $maxy = $this->numOrNull($s, 'maxy');

        if ($minx === null || $maxx === null || $maxy === null) {
            $xs = [0.0];
            $ys = [0.0];

            foreach ($this->scene['children'] as $c) {
                if (isset($c['attrs']['x'])) {
                    $xs[] = (float) $c['attrs']['x'];
                    $ys[] = (float) ($c['attrs']['y'] ?? 0);
                }
            }

            $minx = min($xs);
            $maxx = max($xs);
            $miny = min($ys);
            $maxy = max($ys);
            $this->say('границы сцены не заданы — посчитаны по содержимому');
        }

        $this->minx = $minx;
        $this->maxy = $maxy;
        $this->w = $maxx - $minx;
        $this->h = $maxy - $miny;

        // Комната растягивается до всего, что в ней стоит.
        //
        // Границы сцены в исходнике — это вид, а не стены: губят шары отдельные
        // смертельные прямые, и шар, поставленный за краем, там живёт. У нас
        // край мира — это край мира, и всё за ним пропадает без следа: в
        // `ProductLauncher` шар стоит на тысячу пикселей ниже дна сцены и
        // исчезал на первом же тике.
        //
        // Растягиваем только вниз и вправо, оставляя начало отсчёта на месте:
        // ни одна уже посчитанная точка от этого не сдвинется.
        foreach (WogFile::kids($this->level, 'BallInstance') as $b) {
            $this->w = max($this->w, $this->x($this->num($b['attrs'], 'x', 0)) + 100);
            $this->h = max($this->h, $this->y($this->num($b['attrs'], 'y', 0)) + 100);
        }
    }

    /** The original's y axis points up and ours points down. */
    private function x(float $x): float
    {
        return WogGeometry::fixed($x - $this->minx);
    }

    private function y(float $y): float
    {
        return WogGeometry::fixed($this->maxy - $y);
    }

    /** @return array{0: float, 1: float} */
    private function p(float $x, float $y): array
    {
        return [$this->x($x), $this->y($y)];
    }

    /**
     * Gravity is a level-wide force field there, not a property of the scene.
     */
    private function readGravity(): void
    {
        foreach (WogFile::kids($this->scene, 'linearforcefield') as $f) {
            if (isset($f['attrs']['center'])) {
                continue;
            }

            [$fx, $fy] = $this->pair($f['attrs'], 'force');
            $this->gravity = ['x' => self::fixed($fx * self::G, 1), 'y' => self::fixed(-$fy * self::G, 1)];

            // Сопротивление среды на весь уровень. Само поле сюда не кладём:
            // разделы уровня собираются в строгом порядке, имена сущностей
            // раздаются счётчиком, и вставка здесь сдвинула бы все имена. Поле
            // создаётся вместе с остальными, ниже.
            $this->airDrag = $this->num($f['attrs'], 'dampeningfactor', 0);
        }
    }

    // --- геометрия ------------------------------------------------------------

    private function geometry(): void
    {
        $reach = abs($this->w) + abs($this->h);

        foreach (WogFile::kids($this->scene, 'line') as $l) {
            [$ax, $ay] = $this->pair($l['attrs'], 'anchor');
            [$nx, $ny] = $this->pair($l['attrs'], 'normal', [0.0, 1.0]);
            $pts = WogGeometry::linePoints($ax, $ay, $nx, $ny, $reach, $this->p(...));
            // A wall stays solid and keeps its tags — the bottom edge of many
            // levels is tagged deadly — but it is not drawn: not one of the 140
            // lines in the set has a picture. Terrain in its default colours
            // painted them green, showing the player what they must not see.
            $this->solid($pts, $l['attrs'] + ['invisible' => 'true']);
        }

        foreach (WogFile::kids($this->scene, 'rectangle') as $r) {
            $a = $r['attrs'];
            $made = $this->solid(WogGeometry::rectPoints(
                $this->num($a, 'x', 0),
                $this->num($a, 'y', 0),
                $this->num($a, 'width', 0),
                $this->num($a, 'height', 0),
                $this->num($a, 'rotation', 0),
                $this->p(...),
            ), $a);
            $this->bodySprite($a, $made['static'] ? null : $made['id']);
        }

        foreach (WogFile::kids($this->scene, 'circle') as $c) {
            $a = $c['attrs'];
            $made = $this->solid(WogGeometry::circlePoints(
                $this->num($a, 'x', 0),
                $this->num($a, 'y', 0),
                $this->num($a, 'radius', 0),
                $this->p(...),
            ), $a);
            $this->bodySprite($a, $made['static'] ? null : $made['id']);
        }

        // A composite is a rigid assembly. Taking it apart into separate bodies
        // was the first thing I did on the other side, and it was wrong: the
        // means were there all along. A child whose parent has a rigid body
        // joins it, and a tested L-shape falling on a slope holds the distance
        // between its parts exactly, where separated pieces drift three times
        // as far. The first piece owns it and the rest hang off it.
        foreach (WogFile::kids($this->scene, 'compositegeom') as $g) {
            $gx = $this->num($g['attrs'], 'x', 0);
            $gy = $this->num($g['attrs'], 'y', 0);
            $owner = null;
            $ownerAt = 0;

            // Собственное вращение принадлежит сборке целиком, а не каждому её
            // куску. Куски строятся с общими признаками составной геометрии, и
            // без этой оговорки каждый заводил бы себе двигатель — а потом все,
            // кроме первого, переезжали бы под первый кусок, и лишние двигатели
            // остались бы крутить пустоту. Так и вышло: сорок пять двигателей
            // на двадцать шесть тел.
            $spin = $g['attrs'];
            unset($spin['rotspeed']);

            foreach ($g['children'] as $c) {
                $a = $c['attrs'];
                $made = match ($c['tag']) {
                    'rectangle' => $this->solid(WogGeometry::rectPoints(
                        $gx + $this->num($a, 'x', 0),
                        $gy + $this->num($a, 'y', 0),
                        $this->num($a, 'width', 0),
                        $this->num($a, 'height', 0),
                        $this->num($a, 'rotation', 0),
                        $this->p(...),
                    ), $spin),
                    'circle' => $this->solid(WogGeometry::circlePoints(
                        $gx + $this->num($a, 'x', 0),
                        $gy + $this->num($a, 'y', 0),
                        $this->num($a, 'radius', 0),
                        $this->p(...),
                    ), $spin),
                    default => null,
                };

                // Fixed pieces do not need the assembly: they do not move
                // anyway, and the extra parenthood only muddles the tree.
                if ($made === null || $made['static']) {
                    continue;
                }

                if ($owner === null) {
                    $owner = $made['id'];
                    $ownerAt = $made['at'];
                } else {
                    $this->entities[$made['at']]['parent'] = $owner;
                }
            }

            $turn = $this->num($g['attrs'], 'rotspeed', 0);

            if ($owner !== null && $turn !== 0.0) {
                $axle = $this->id('mot');
                $this->entities[] = ['id' => $axle, 'type' => 'motor', 'data' => [
                    'x' => WogGeometry::fixed($this->x($gx), 2), 'y' => WogGeometry::fixed($this->y($gy), 2),
                    'r' => 26, 'hard' => true,
                    'speed' => WogGeometry::fixed($turn * 50 / (2 * M_PI), 3),
                    'torque' => 60.0, 'color' => '#c58a4b',
                ]];
                $this->entities[$ownerAt]['parent'] = $axle;
            }

            $this->bodySprite($g['attrs'], $owner);
        }
    }

    /**
     * One solid shape: fixed becomes terrain, movable becomes an object.
     *
     * @param list<array{0: float, 1: float}> $pts
     * @param array<string, string>           $attrs
     *
     * @return array{id: string, static: bool, at: int}
     */
    private function solid(array $pts, array $attrs): array
    {
        $m = $this->mats[$attrs['material'] ?? ''] ?? ['smoothness' => 0.35, 'restitution' => 0.1];
        $static = ($attrs['static'] ?? 'true') !== 'false';
        $surface = WogGeometry::surfaceOf($attrs['tag'] ?? null, fn (string $w) => $this->say($w));

        // Порог разрушения бывает только у подвижного. У неподвижной геометрии
        // он и в исходнике ничего не значит — разлетаться нечему, — а поля под
        // него у рельефа нет, и заводить его ради значения, которое никогда не
        // сработает, значило бы предложить автору бесполезную настройку.
        if ($static) {
            unset($surface['breakForce']);
        }


        $paint = isset($attrs['invisible'])
            ? ['fill' => 'transparent', 'edge' => 'transparent']
            : [];

        // Три свойства, которых раньше не читали вовсе, — и каждое меняет, как
        // уровень играется.
        //
        // `contacts="false"` — геометрия без твёрдости. Отдельного описания у
        // атрибута нет (страница «Making Geometry less Solid» на goofans так и
        // не написана), и смысл выведен из данных, а не прочитан. В наборе
        // таких кусков 32. Пятнадцать названы как датчики — `killarea`,
        // `detachArea`, `bustArea`, `endDetector`, `invisiDetacher` — и несут
        // теги, действующие на касание: сделанные твёрдыми, они стояли стенами
        // посреди прохода. Остальные семнадцать — скелет робота в
        // `Deliverance`, который виден только в развязке и не должен мешать
        // до неё.
        //
        // `nogeomcollisions="true"` — сквозь неё проходит другая геометрия, но
        // не шары. Так описан приём в разборе механизмов на goofans: планку над
        // устьем трубы блоки проходят, а шары нет. В наборе так сидит голова
        // на шее в `Chain` и рука в гнезде в `GeneticSortingMachine`.
        //
        // `strandgeom` — настройка уровня: запрещено ли строить сквозь
        // геометрию. У нас это свойство каждой поверхности, поэтому уровень
        // раздаёт его всей своей геометрии. Нетвёрдая строить не мешает при
        // любом значении — мешать там нечему.
        $solidness = ($attrs['contacts'] ?? '') !== 'false';
        $flags = [
            'solid' => $solidness,
            'touchesBodies' => ($attrs['nogeomcollisions'] ?? '') !== 'true',
            'blocksLinks' => $solidness && ($this->level['attrs']['strandgeom'] ?? 'false') === 'true',
        ];

        if ($static) {
            $id = $this->id('t');
            $data = [
                'points' => $pts, 'smoothness' => $m['smoothness'], 'bounce' => $m['restitution'],
                'walkable' => true, 'deadly' => false, 'sparesTough' => false,
                'detaching' => false, 'bursting' => false, 'sticky' => 0.0, 'stopsign' => false,
                ...$flags,
                'fill' => '#2a3326', 'edge' => '#66804f',
            ];
        } else {
            $id = $this->id('o');
            $data = [
                // Масса подвижного тела — тем же множителем, что у шаров.
                //
                // Раньше она переносилась как есть, и тело выходило в тридцать
                // раз тяжелее шара той же тяжести: шар массой 30 приезжал
                // единицей, а ящик массой 500 — пятьюстами. Конструкция под
                // таким ящиком проседала не потому, что он тяжёлый, а потому,
                // что единицы разные.
                //
                // Проверено прогоном по всем 47 уровням: со сведённой массой
                // построек стоит 42 вместо 40, осыпается 4 вместо 6. То есть
                // это не только правильнее по единицам, но и ближе к тому, как
                // уровни задуманы.
                //
                // Умолчание 180 — это наши 6 после деления: тело без
                // указанной массы ведёт себя как прежде.
                'points' => $pts, 'mass' => WogGeometry::fixed($this->num($attrs, 'mass', 180) * WogBallKind::MASS, 3),
                'smoothness' => $m['smoothness'], 'restitution' => $m['restitution'],
                'static' => false,
                'walkable' => true, 'deadly' => false, 'sparesTough' => false,
                'detaching' => false, 'bursting' => false, 'sticky' => 0.0, 'stopsign' => false,
                'breakForce' => 0.0,
                ...$flags,
                // Слово-ключ: имя тела из исходника, если оно есть. Замок с
                // таким же словом от него откроется.
                'key' => (string) ($attrs['id'] ?? ''),
                'pivots' => [],
                'fill' => '#5c5346', 'edge' => '#8d7f68',
            ];
        }

        // Собственное вращение — это двигатель, а не свойство тела.
        //
        // У нас ось, вокруг которой что-то крутится, уже есть отдельной
        // сущностью: к ней привязывают родством что угодно — тело, рельеф,
        // песок. Заводить телу ещё и своё поле «сам кручусь» значило бы
        // второй способ сказать то же самое, из которых один беднее: у мотора
        // есть характер (жёсткий или упругий) и предел момента.
        //
        // Единицы. В исходнике счёт идёт на радианы за такт, а такт там 1/50 —
        // тем же множителем пересчитаны скорости лазания и ходьбы у шаров. У
        // мотора счёт на обороты в секунду, отсюда 50/2π: самое частое в наборе
        // 0.035 превращается в 0.28 оборота в секунду, то есть круг за три с
        // половиной секунды — как и выглядят там шестерни.
        $spin = $this->num($attrs, 'rotspeed', 0);
        $axle = null;

        if ($spin !== 0.0) {
            $axle = $this->id('mot');
            $mid = WogGeometry::middleOf($pts);
            $this->entities[] = ['id' => $axle, 'type' => 'motor', 'data' => [
                'x' => $mid[0], 'y' => $mid[1], 'r' => 26,
                // Жёсткий: в исходнике такое вращение ничем не остановить, оно
                // не сила, а заданное движение.
                'hard' => true,
                'speed' => WogGeometry::fixed($spin * 50 / (2 * M_PI), 3),
                'torque' => 60.0, 'color' => '#c58a4b',
            ]];
        }

        $entity = [
            'id' => $id,
            'type' => $static ? 'terrain' : 'object',
            'data' => array_merge($data, $surface, $paint),
        ];

        if ($axle !== null) {
            $entity['parent'] = $axle;
        }

        $this->entities[] = $entity;

        $made = ['id' => $id, 'static' => $static, 'at' => count($this->entities) - 1];

        // Запоминаем, где стоит тело: отдельный двигатель ссылается на него по
        // имени, а строится позже — геометрия к тому времени уже разобрана.
        if (isset($attrs['id'])) {
            $mid = WogGeometry::middleOf($pts);
            $box = WogGeometry::boxOf($pts);
            $this->geomMiddle[$attrs['id']] = [
                'x' => $mid[0], 'y' => $mid[1], 'at' => $made['at'],
                'w' => $box['w'], 'h' => $box['h'],
                'mass' => (float) ($data['mass'] ?? 1.0),
                'still' => $static,
            ];
        }

        if (isset($attrs['id']) && !isset($this->byGeomId[$attrs['id']])) {
            $this->byGeomId[$attrs['id']] = $made;
        }

        return $made;
    }

    /**
     * The sprite stretched over a body.
     *
     * A fixed body's picture just stands there; a moving one's is tied to it by
     * parenthood and travels with it. Coordinates are absolute because a
     * parent's frame accumulates the offset from zero.
     *
     * @param array<string, string> $attrs
     */
    private function bodySprite(array $attrs, ?string $parent): void
    {
        if (!isset($attrs['image'])) {
            return;
        }

        $rel = $this->res['images'][$attrs['image']] ?? null;
        $size = $rel === null ? null : WogFile::pngSize($this->root . '/' . $rel . '.png');

        if ($size === null) {
            $this->say('спрайт тела: файл не найден');

            return;
        }

        [$sx, $sy] = $this->pair($attrs, 'imagescale', [1.0, 1.0]);
        [$px, $py] = $this->pair($attrs, 'imagepos');
        $w = $size['w'] * $sx;
        $h = $size['h'] * $sy;
        $this->media[$attrs['image']] = $rel . '.png';

        $e = [
            'id' => $this->id('bpic'),
            'type' => 'picture',
            'data' => $this->picture(
                self::fixed($this->x($px) - $w / 2, 2),
                self::fixed($this->y($py) - $h / 2, 2),
                self::fixed($w, 2),
                self::fixed($h, 2),
                self::fixed(-$this->num($attrs, 'imagerot', 0) * 180 / M_PI, 2),
                1.0,
                self::MIDGROUND,
                $this->tintOf($attrs['colorize'] ?? null),
                $rel . '.png',
            ),
        ];

        if ($parent !== null) {
            $e['parent'] = $parent;
        }

        $this->entities[] = $e;
    }

    /**
     * A hinge is an axis, given as a point.
     *
     * If nobody shares it the body hangs on it and swings; if two bodies name
     * the same point they turn relative to each other. Half the hinges in the
     * set have a second body, and the two cases need no telling apart — the
     * shared position is enough.
     */
    private function hinges(): void
    {
        // Сначала те шарниры, что держат тело за мир, и только потом сварка.
        // Порядок нужен затем, чтобы при сварке уже было видно, какая из двух
        // сторон закреплена: она и должна стать старшей.
        $all = WogFile::kids($this->scene, 'hinge');
        $order = array_merge(
            array_filter($all, static fn (array $h): bool => !isset($h['attrs']['body2'])),
            array_filter($all, static fn (array $h): bool => isset($h['attrs']['body2'])),
        );

        foreach ($order as $h) {
            $a = $this->byGeomId[$h['attrs']['body1'] ?? ''] ?? null;
            $second = $h['attrs']['body2'] ?? null;
            $b = $second === null ? null : ($this->byGeomId[$second] ?? null);

            if ($a === null || ($second !== null && $b === null)) {
                $this->say('шарнир ссылается на неизвестное тело');

                continue;
            }

            [$axw, $ayw] = $this->pair($h['attrs'], 'anchor');
            $anchor = $this->p($axw, $ayw);

            // A turn limit whose top and bottom coincide is not a hinge at all;
            // it is a weld, and parenthood already expresses that.
            $lo = $this->numOrNull($h['attrs'], 'lostop');
            $hi = $this->numOrNull($h['attrs'], 'histop');

            if ($lo !== null && $hi !== null && $lo === $hi) {
                if ($b !== null) {
                    if (!$a['static'] && !$b['static']) {
                        // Старшим становится тот, кто сам за что-то держится.
                        //
                        // В записи стороны равноправны: кто назван первым, дело
                        // случая. А у нас старший ведёт младшего за собой, и
                        // поставить старшим свободное тело значит подвесить
                        // связку в пустоту. В `RedCarpet` так и вышло: рычаг с
                        // опорой стал младшим при свободном лезвии, всё это
                        // поехало вниз, накрыло постройку и убило шесть шаров
                        // за три секунды — а по прохождению ковёр там убивает
                        // только после того, как игрок опустит груз.
                        $holds = fn (array $side): bool => $side['static']
                            || ($this->entities[$side['at']]['data']['pivots'] ?? []) !== [];

                        [$up, $down] = $holds($b) && !$holds($a) ? [$b, $a] : [$a, $b];
                        $this->entities[$down['at']]['parent'] = $up['id'];
                    }

                    continue;
                }

                // Шарнир с ОДНИМ телом держит его за мир: по описанию приёма
                // тело после этого «двигается, но само не вращается». А
                // совпавшие пределы поворота отнимают и это — тело просто
                // приколочено на месте.
                //
                // Раньше сюда попадала и такая запись, и её принимали за
                // сварку двух тел; сваривать было не с чем, и тело оставалось
                // свободным. В `InfestyTheWorm` на таких шарнирах висят все
                // четыре площадки, и без них уровень осыпался целиком: сорок
                // шаров из сорока четырёх улетали вниз за две секунды.
                $this->entities[$a['at']]['data']['static'] = true;

                continue;
            }

            if ($lo !== null && $hi !== null) {
                $this->say('пределы поворота шарнира');
            }

            if (isset($h['attrs']['bounce'])) {
                $this->say('отскок шарнира на упоре');
            }

            foreach ([$a, $b] as $side) {
                if ($side === null || $side['static']) {
                    continue;
                }

                $this->entities[$side['at']]['data']['pivots'][] = $anchor;
            }

            if ($a['static'] || ($b !== null && $b['static'])) {
                $this->say('шарнир на неподвижном теле');
            }
        }
    }

    // --- декорация ------------------------------------------------------------

    /**
     * Depth is laid out by order, not by absolute value.
     *
     * The two engines use different scales, but the order the layers sit in is
     * exactly what has to survive. Signs take part in the same reckoning
     * because they share the same depth axis in the original.
     */
    private function layerOf(?string $depth): int
    {
        if (count($this->depths) < 2) {
            return self::BACKGROUND;
        }

        // Глубина, которой нет среди слоёв, даёт −1 и уезжает ЗА всё.
        //
        // Это не задумано, а получилось: список глубин собран из слоёв и
        // табличек, а источники частиц в него не входят, и `indexOf` на той
        // стороне отдаёт −1. Повторяю точно — перенос обязан совпадать, — но
        // это стоит починить в обоих местах разом, а не тихо разойтись здесь.
        $i = array_search((float) ($depth ?? 0), $this->depths, true);
        $i = $i === false ? -1 : $i;

        return WogGeometry::whole(self::BACKGROUND - 10 + ($i / (count($this->depths) - 1)) * 120);
    }

    private function decoration(): void
    {
        $layers = WogFile::kids($this->scene, 'SceneLayer');
        $signs = WogFile::kids($this->level, 'signpost');

        $depths = [];

        foreach ([...$layers, ...$signs] as $l) {
            $depths[] = $this->num($l['attrs'], 'depth', 0);
        }

        $this->depths = array_values(array_unique($depths));
        sort($this->depths);

        foreach ($layers as $l) {
            $this->drawLayer($l['attrs']);
        }

        // The board itself draws like any other layer. Its text does not come
        // across: in the original it appears in a popup when the player walks
        // up, and the size shows why — five lines of sixty characters on
        // average, up to twenty-three at the extreme. Writing that into the
        // level would not be carrying the content over, it would be burying the
        // level under a wall of text.
        foreach ($signs as $sp) {
            $this->drawLayer($sp['attrs']);
            $text = $this->strings[$sp['attrs']['text'] ?? ''] ?? null;

            if ($text === null) {
                $this->say('текст таблички не найден в строках');

                continue;
            }

            // Reach is measured from the board's own size: walking closer to a
            // big hoarding than to a small signpost would be odd.
            $rel = $this->res['images'][$sp['attrs']['image'] ?? ''] ?? null;
            $dim = $rel === null ? null : WogFile::pngSize($this->root . '/' . $rel . '.png');
            $w0 = $dim !== null ? $dim['w'] * $this->num($sp['attrs'], 'scalex', 1) : 120;
            $h0 = $dim !== null ? $dim['h'] * $this->num($sp['attrs'], 'scaley', 1) : 120;

            $this->entities[] = ['id' => $this->id('sign'), 'type' => 'sign', 'data' => [
                'x' => $this->x($this->num($sp['attrs'], 'x', 0)),
                'y' => $this->y($this->num($sp['attrs'], 'y', 0)),
                'r' => WogGeometry::whole(max(70, max($w0, $h0) * 0.7)),
                'text' => $text,
                'size' => 22,
                'color' => '#f2ead8', 'panel' => '#241f18', 'edge' => '#8a7a5c',
                'depth' => self::OVERLAY - 5,
            ]];
        }

        $this->labels();
    }

    /** @param array<string, string> $a */
    private function drawLayer(array $a): void
    {
        $rel = $this->res['images'][$a['image'] ?? ''] ?? null;

        if ($rel === null) {
            $this->say('картинка не найдена в манифесте ресурсов');

            return;
        }

        $size = WogFile::pngSize($this->root . '/' . $rel . '.png');

        if ($size === null) {
            $this->say('файл картинки отсутствует');

            return;
        }

        $w = $size['w'] * $this->num($a, 'scalex', 1);
        $h = $size['h'] * $this->num($a, 'scaley', 1);
        $this->media[$a['image']] = $rel . '.png';

        // Замощение. По оси, помеченной `tilex`/`tiley`, масштаб означает не
        // растяжение, а сколько раз положить картинку: полоса помех — это одна
        // узкая картинка при `scalex="50"`, то есть пятьдесят повторов, а не
        // растянутая в пятьдесят раз мазня.
        //
        // Размер занятой области при этом тот же самый — он и так равен размеру
        // картинки, умноженному на масштаб. Меняется только то, чем область
        // заполняется: у нас это размер плитки, и он равен исходной картинке.
        // Повторы дробные (`scalex="1.586"`), поэтому счётом их не выразить, не
        // соврав про ширину, — последняя плитка обрезана по краю рамки.
        $tileW = ($a['tilex'] ?? '') === 'true' ? WogGeometry::fixed($size['w'], 2) : 0.0;
        $tileH = ($a['tiley'] ?? '') === 'true' ? WogGeometry::fixed($size['h'], 2) : 0.0;

        $this->entities[] = ['id' => $this->id('pic'), 'type' => 'picture', 'data' => $this->picture(
            // There x,y is the middle of the picture; here it is the top left.
            self::fixed($this->x($this->num($a, 'x', 0)) - $w / 2, 2),
            self::fixed($this->y($this->num($a, 'y', 0)) - $h / 2, 2),
            self::fixed($w, 2),
            self::fixed($h, 2),
            self::fixed(-$this->num($a, 'rotation', 0), 2),
            self::fixed($this->num($a, 'alpha', 1), 3),
            $this->layerOf($a['depth'] ?? null),
            $this->tintOf($a['colorize'] ?? null),
            $rel . '.png',
            $tileW,
            $tileH,
        )];

        if (isset($a['anim'])) {
            $this->animate($a, count($this->entities) - 1);
        }

        // A colourise that differs per channel would need a matrix, not a
        // brightness. There are none in this set, but if any appear we hear
        // about it rather than quietly losing the colour.
        $cz = array_map('floatval', array_filter(explode(',', $a['colorize'] ?? '')));

        if (count($cz) === 3 && !($cz[0] === $cz[1] && $cz[1] === $cz[2])) {
            $this->say('подкраска разная по каналам — яркостью не выразить');
        }
    }

    /**
     * Frame animation, split between a track and the picture.
     *
     * Travel and rotation are movement: it rides through parenthood and suits a
     * body as well as a risunok, so it belongs in a track. Scale and
     * transparency cannot travel that way — a keyframe carries a position and
     * nothing else — so they stay with the picture.
     *
     * @param array<string, string> $a
     */
    private function animate(array $a, int $at): void
    {
        $name = $a['anim'];
        $file = $this->animFile($name);

        if ($file === null) {
            $this->say("анимация «{$name}» не найдена");

            return;
        }

        $anim = WogAnim::read($file);

        if ($anim['tracks'] === []) {
            $this->say("анимация «{$name}» ничего не двигает");

            return;
        }

        // Speed is sometimes negative — that is how a backwards run is written,
        // and nearly a third of them are. Times cannot be divided by a negative:
        // the track turns inside out and the duration comes out below zero. We
        // reverse the order of the points and take the magnitude for the length.
        $raw = $this->num($a, 'animspeed', 1) ?: 1;
        $speed = abs($raw);
        $back = $raw < 0;
        $delay = $this->num($a, 'animdelay', 0);
        $dur = WogGeometry::fixed($anim['dur'] / $speed, 4);

        $scaled = [];

        foreach ($anim['tracks'] as $k => $pts) {
            $moved = [];

            foreach ($pts as [$t, $v]) {
                $moved[] = [WogGeometry::fixed($t / $speed, 4), $v];
            }

            if ($back) {
                $flipped = [];

                foreach ($moved as [$t, $v]) {
                    $flipped[] = [WogGeometry::fixed($dur - $t, 4), $v];
                }

                $moved = array_reverse($flipped);
            }

            if ($delay !== 0.0) {
                // A delay shifts the start, and the first point is held at zero
                // so the picture stays where it was put until then.
                $shifted = [[0.0, $moved[0][1]]];

                foreach ($moved as [$t, $v]) {
                    $shifted[] = [WogGeometry::fixed($t + $delay, 4), $v];
                }

                $moved = $shifted;
            }

            $scaled[$k] = $moved;
        }

        $total = WogGeometry::fixed($dur + $delay, 4);

        // The y axis is flipped, and so are the signs that follow it: a rise
        // stays a rise and a counter-clockwise turn stays counter-clockwise.
        // Scale and transparency are untouched — they do not depend on which
        // way the axes point.
        foreach (['dy', 'rot'] as $k) {
            if (!isset($scaled[$k])) {
                continue;
            }

            foreach ($scaled[$k] as $i => [$t, $v]) {
                $scaled[$k][$i] = [$t, WogGeometry::fixed(-$v, 4)];
            }
        }

        $move = array_intersect_key($scaled, ['dx' => 1, 'dy' => 1, 'rot' => 1]);
        $draw = array_intersect_key($scaled, ['sx' => 1, 'sy' => 1, 'alpha' => 1]);

        if ($move !== []) {
            // The track sits exactly at the middle of the picture: a rotation
            // there turns about the middle, not about a corner.
            $trk = ['id' => $this->id('trk'), 'type' => 'track', 'data' => [
                'x' => $this->x($this->num($a, 'x', 0)),
                'y' => $this->y($this->num($a, 'y', 0)),
                'keys' => WogAnim::keysOf($move, ['dx' => 0.0, 'dy' => 0.0, 'rot' => 0.0]),
                'dur' => $total,
                'loop' => true,
                'signal' => '', 'invert' => false,
                // Movement carried over, not a sketch for the author.
                'show' => false,
                'color' => '#c8a2e0',
            ]];

            // A parent must be in the same level; it goes in before its child so
            // the list reads top to bottom.
            array_splice($this->entities, $at, 0, [$trk]);
            $this->entities[$at + 1]['parent'] = $trk['id'];
            $at++;
        }

        if ($draw !== []) {
            $this->entities[$at]['data']['anim'] = WogAnim::keysOf($draw, ['sx' => 1.0, 'sy' => 1.0, 'alpha' => 1.0]);
            $this->entities[$at]['data']['animDur'] = $total;
            $this->entities[$at]['data']['animLoop'] = true;
        }
    }

    private function animFile(string $name): ?string
    {
        foreach ([$this->root . "/anim/{$name}.anim.binltl", $this->root . "/{$name}.anim.binltl"] as $try) {
            if (is_file($try)) {
                return $try;
            }
        }

        return null;
    }

    private function labels(): void
    {
        foreach (WogFile::kids($this->scene, 'label') as $l) {
            $a = $l['attrs'];
            $text = $this->strings[$a['text'] ?? ''] ?? null;

            if ($text === null) {
                $this->say('подпись не найдена в строках');

                continue;
            }

            if (preg_match('/\{\d\}/', $text) === 1) {
                $this->say('подпись с подстановкой значения');

                continue;
            }

            $this->entities[] = ['id' => $this->id('lbl'), 'type' => 'label', 'data' => [
                'x' => $this->x($this->num($a, 'x', 0)),
                'y' => $this->y($this->num($a, 'y', 0)),
                'text' => $text,
                'size' => self::fixed(24 * $this->num($a, 'scale', 1), 1),
                'align' => ['left' => 'start', 'right' => 'end'][$a['align'] ?? ''] ?? 'middle',
                'rot' => self::fixed(-$this->num($a, 'rotation', 0), 2),
                'color' => '#f2ead8', 'outline' => '#1b1710',
                'depth' => $this->layerOf($a['depth'] ?? null),
            ]];

            if (($a['screenspace'] ?? '') === 'true') {
                $this->say('подпись, привязанная к экрану, а не к миру');
            }
        }
    }

    // --- частицы --------------------------------------------------------------

    private function particles(): void
    {
        foreach (WogFile::kids($this->scene, 'particles') as $em) {
            $eff = $this->fx[$em['attrs']['effect'] ?? ''] ?? null;

            if ($eff === null) {
                $this->say("эффект «{$em['attrs']['effect']}» не найден");

                continue;
            }

            if (isset($em['attrs']['pos'])) {
                [$px, $py] = $this->pair($em['attrs'], 'pos');
                $spot = ['x' => $this->x($px), 'y' => $this->y($py), 'w' => 0.0, 'h' => 0.0];
            } else {
                // An ambient effect falls over the whole level.
                $spot = ['x' => $this->w / 2, 'y' => $this->h / 2, 'w' => $this->w, 'h' => $this->h];
            }

            $maxParts = $this->num($eff['attrs'], 'maxparticles', 10);
            $rate = $this->num($eff['attrs'], 'rate', 0);

            foreach ($eff['parts'] as $part) {
                $this->emitter($part['attrs'], $spot, $maxParts, $rate, $em['attrs']['depth'] ?? null);
            }
        }
    }

    /**
     * @param array<string, string>                          $a
     * @param array{x: float, y: float, w: float, h: float}   $spot
     */
    /**
     * Вспышка частиц по описанию эффекта — теми же числами, что и постоянный
     * источник в уровне, только вокруг точки и на один раз.
     *
     * Отдельным входом, потому что этим же переводом пользуется сборка ассетов:
     * лопающийся шар и бомба носят свой эффект с собой, и собирается он не в
     * уровне, а рядом с шаром.
     *
     * @param array<string, string> $a
     * @param array<string, string> $images
     *
     * @return array{data: array<string, mixed>, media: array<string, string>}|null
     */
    public static function burst(array $a, array $images, string $root, float $maxParts, float $rate): ?array
    {
        $empty = ['tag' => 'x', 'attrs' => [], 'children' => []];
        $level = new self(
            'burst',
            $empty,
            $empty,
            ['images' => $images, 'sounds' => []],
            $root,
            [],
            [],
            [],
            [],
            static function (string $what, int $count): void {
                // Вспышка у шара ничего не теряет молча: всё, что мог бы
                // сказать перевод, уже сказано про тот же эффект в уровне.
            },
        );
        $level->emitter($a, ['x' => 0.0, 'y' => 0.0, 'w' => 0.0, 'h' => 0.0], $maxParts, $rate, null);

        if ($level->entities === []) {
            return null;
        }

        return ['data' => $level->entities[0]['data'], 'media' => $level->media];
    }

    /**
     * @param array<string, string>                        $a
     * @param array{x: float, y: float, w: float, h: float} $spot
     */
    private function emitter(array $a, array $spot, float $maxParts, float $rate, ?string $depth): void
    {
        $srcs = [];
        $size = 32;

        foreach (array_filter(array_map('trim', explode(',', $a['image'] ?? ''))) as $key) {
            $rel = $this->res['images'][$key] ?? null;
            $dim = $rel === null ? null : WogFile::pngSize($this->root . '/' . $rel . '.png');

            if ($dim === null) {
                continue;
            }

            $this->media[$key] = $rel . '.png';
            $srcs[] = $rel . '.png';
            $size = max($size, $dim['w'], $dim['h']);
        }

        if ($srcs === []) {
            $this->say('картинка частицы не найдена');

            return;
        }

        [$lifeMin, $lifeMax] = $this->span($a, 'lifespan', 3);
        [$spMin, $spMax] = $this->span($a, 'speed', 1);
        [$scMin, $scMax] = $this->span($a, 'scale', 1);
        [$rotMin, $rotMax] = $this->span($a, 'rotation', 0);
        [$spinMin, $spinMax] = $this->span($a, 'rotspeed', 0);
        [$aax, $aay] = $this->pair($a, 'acceleration');

        // No more alive at once than can be born within one lifetime.
        $alive = $rate > 0 ? min($maxParts, ceil($lifeMax / $rate)) : $maxParts;

        $this->entities[] = ['id' => $this->id('fx'), 'type' => 'sparks', 'data' => [
            'x' => $spot['x'], 'y' => $spot['y'], 'w' => $spot['w'], 'h' => $spot['h'],
            'srcs' => $srcs, 'size' => $size,
            'count' => (int) max(1, $alive),
            'lifeMin' => $lifeMin, 'lifeMax' => $lifeMax,
            'speedMin' => self::fixed($spMin * self::V, 1), 'speedMax' => self::fixed($spMax * self::V, 1),
            // The y axis is flipped, so the direction is too.
            'dir' => self::fixed(-$this->num($a, 'movedir', 0), 1),
            'spread' => abs($this->num($a, 'movedirvar', 0)),
            'ax' => self::fixed($aax * self::A, 1), 'ay' => self::fixed(-$aay * self::A, 1),
            'drag' => 0,
            'scaleMin' => $scMin, 'scaleMax' => $scMax,
            'finalScale' => isset($a['finalscale']) ? $this->num($a, 'finalscale', 0) : -1,
            'rotMin' => $rotMin, 'rotMax' => $rotMax,
            'spinMin' => $spinMin, 'spinMax' => $spinMax,
            'fade' => ($a['fade'] ?? '') === 'true',
            // Оба поля были в отчёте как непереносимые, пока в движке для них
            // не нашлось места: повернуть картинку по движению и прибавлять
            // цвет к фону вместо замены. Ни то, ни другое пересчёта не требует
            // — признак так признаком и остаётся.
            'directed' => ($a['directed'] ?? '') === 'true',
            'additive' => ($a['additive'] ?? '') === 'true',
            'opacity' => 1,
            'depth' => $this->layerOf($depth),
        ]];

        if (isset($a['dampening'])) {
            $this->say('затухание частиц (шкала не сверена)');
        }

    }

    // --- поля, вода, трубы, огонь, звук, камера --------------------------------

    private function fields(): void
    {
        // Сопротивление среды на весь уровень — поле без тяги во всю комнату.
        //
        // Единицы совпадают, и это проверено, а не принято на веру: у нас
        // сопротивление задаётся в 1/с (`kd = exp(-drag·h)`), у исходника
        // `dampeningfactor` — тот же коэффициент при скорости, только
        // прикладываемый явным шагом. Известная в сообществе поломка исходника
        // — тряска при значении около сорока — как раз следствие явного шага:
        // за такт гасящая сила перелетает через ноль. У нас гашение
        // показательное, поэтому оно устойчиво при любой величине, и переносить
        // число можно как есть.
        if ($this->airDrag > 0) {
            $this->entities[] = ['id' => $this->id('ff'), 'type' => 'field', 'data' => [
                'x' => WogGeometry::whole($this->w / 2), 'y' => WogGeometry::whole($this->h / 2),
                'w' => WogGeometry::whole($this->w), 'h' => WogGeometry::whole($this->h),
                'ax' => 0.0, 'ay' => 0.0,
                // Тяга уже уехала в тяготение уровня, здесь остаётся только
                // сопротивление, и оно про скорость, а не про вес.
                'byMass' => false,
                'damping' => $this->airDrag,
                'enabled' => true,
                'geomOnly' => false,
                'signal' => '', 'invert' => false,
                'show' => false, 'color' => '#7fb6cc',
            ]];
        }

        foreach (WogFile::kids($this->scene, 'linearforcefield') as $f) {
            $a = $f['attrs'];

            if (!isset($a['center'])) {
                continue;
            }

            [$cxw, $cyw] = $this->pair($a, 'center');
            $cx = $this->x($cxw);
            $cy = $this->y($cyw);
            $w0 = abs($this->num($a, 'width', 0));
            $h0 = abs($this->num($a, 'height', 0));

            // Water there is a static rectangle with a steady lift and a drag.
            // No current, no changing shape: it needs no particles at all. I
            // sent it to the real fluid at first, generalising from one sample
            // whose force happened to be zero; across all twenty-eight it is
            // plainly not so, twenty-one of them have a force.
            if (($a['water'] ?? '') === 'true') {
                [, $upw] = $this->pair($a, 'force');
                // Density is how much stronger the lift is than gravity. The
                // ratio is dimensionless, so it carries over untouched: force
                // 20 against gravity 10 means two, and a body floats half out.
                $gy = abs($this->gravity['y']) ?: 1800;
                $density = self::fixed($upw * self::G / $gy, 2);

                // Вода без подъёмной силы — не пробел, а замысел: у четырёх
                // водоёмов из двадцати восьми сила ноль и в исходнике. Такая
                // вода только тормозит, и тонут в ней и там, и у нас. Раньше
                // здесь стояла строка отчёта, и читалась она как наш
                // недостаток.
                //
                // Сопротивление берётся как есть, включая ноль. Раньше ноль
                // заменялся четвёркой, и тринадцать водоёмов из двадцати
                // восьми — те, где автор нарочно сделал воду, не мешающую
                // движению, — получали вязкость из воздуха. Признак стоит у
                // всех двадцати восьми явно, так что «не задано» тут не бывает
                // вовсе, и подставлять было нечего и незачем.
                $damp = $this->num($a, 'dampeningfactor', 0);

                $this->entities[] = ['id' => $this->id('pool'), 'type' => 'pool', 'data' => [
                    'x' => $cx, 'y' => $cy, 'w' => $w0, 'h' => $h0,
                    'density' => max(0, $density),
                    'drag' => $damp,
                    'waves' => true,
                    'color' => '#2f6f8f', 'surface' => '#9fd8ef',
                ]];

                continue;
            }

            [$fx, $fy] = $this->pair($a, 'force');

            // Поле, которое СЧИТАЕТСЯ ПО ВЕСУ, переводится другим множителем.
            //
            // У нас такое поле делит тягу на массу шара, и масса эта уже
            // уменьшена в тридцать раз против исходной. Умножать при этом на
            // полный множитель значит прикладывать его дважды: ускорение
            // выходит в тридцать раз сильнее, чем в исходнике.
            //
            // Разница не отвлечённая. В `VolcanicPercolatorDaySpa` струя
            // гейзера должна еле замедлять падение (сила 5 на массу 30 — это
            // тридцатая доля тяготения), а у нас швыряла постройку на полтысячи
            // пикселей за секунду, и та влетала в отцепляющие шестерни.
            //
            // Поле, которое веса не замечает (`antigrav="true"`), — это сразу
            // ускорение, и ему нужен прежний множитель.
            $byMass = ($a['antigrav'] ?? '') !== 'true';
            $k = $byMass ? self::G * WogBallKind::MASS : self::G;

            $this->entities[] = ['id' => $this->id('ff'), 'type' => 'field', 'data' => [
                'x' => $cx, 'y' => $cy, 'w' => $w0, 'h' => $h0,
                'ax' => self::fixed($fx * $k, 1),
                'ay' => self::fixed(-$fy * $k, 1),
                // antigrav there means "pays no attention to weight", that is,
                // an acceleration. Without it this is a force, and heavy things
                // resist it more.
                'byMass' => $byMass,
                'damping' => $this->num($a, 'dampeningfactor', 0),
                // Оставленное про запас поле переносится выключенным, а не
                // выбрасывается: автор увидит его в редакторе там, где оно
                // задумано, и включит одной галкой.
                'enabled' => ($a['enabled'] ?? '') !== 'false',
                // Только на тела, не на шары. Отличаются они не типом — мир
                // типов не знает, — а тем, входит ли точка в твёрдое тело.
                'geomOnly' => ($a['geomonly'] ?? '') === 'true',
                'signal' => '', 'invert' => false,
                'show' => false, 'color' => '#7fb6cc',
            ]];

        }

        // Отдельный двигатель. В наборе он один — колесо-убийца с натянутой на
        // него головой в `YouHaveToExplodeTheHead`, — а всё остальное, что
        // вращается, задано через `rotspeed` прямо на теле и разбирается
        // вместе с геометрией. Отличается он одним: у него есть `maxforce`, то
        // есть такой двигатель можно застопорить.
        //
        // Это переносится прямо: у нашего двигателя ровно для того и есть
        // `hard`. Жёсткий крутит безусловно, упругий ограничен `torque`. Тот,
        // что приходит из `rotspeed`, остаётся жёстким — предела у него нет.
        //
        // Порог считается, а не подбирается. Наш `torque` — предел углового
        // ускорения, чужой `maxforce` — сила на ободе, и между ними обычная
        // механика: α = M / I, где M это сила на плечо, а I — момент инерции
        // тела.
        //
        // Множитель силы берётся из согласованной тройки (длина и время 1:1,
        // ускорение ×180, масса ×1/30, значит сила ×6), а НЕ из предела
        // разрыва связи: тот несёт в себе поправку на разницу решателей — ×43
        // вместо ×6 — и потянул бы её в эту формулу. Проверено опытом: со
        // сведённым пределом разрыва половина построек в наборе рассыпается,
        // то есть поправка там нужна, а здесь её быть не должно.
        foreach (WogFile::kids($this->scene, 'motor') as $m) {
            $a = $m['attrs'];
            $at = $this->geomMiddle[$a['body'] ?? ''] ?? null;

            if ($at === null) {
                $this->say('двигатель без тела');

                continue;
            }

            $limited = isset($a['maxforce']);


            $axle = $this->id('mot');
            $this->entities[] = ['id' => $axle, 'type' => 'motor', 'data' => [
                'x' => $at['x'], 'y' => $at['y'], 'r' => 26, 'hard' => !$limited,
                'speed' => WogGeometry::fixed($this->num($a, 'speed', 0) * 50 / (2 * M_PI), 3),
                'torque' => $limited
                    ? self::spinLimit($this->num($a, 'maxforce', 0), $at)
                    : 60.0,
                'color' => '#c58a4b',
            ]];
            $this->entities[$at['at']]['parent'] = $axle;
        }

        // Радиальное поле — это наш источник притяжения, взятый изнутри.
        //
        // В исходнике сила задана в двух точках: в середине и на краю, между
        // ними линейно. Ровно это и есть внутренность нашего источника: тело
        // размером с поле, `core` в середине, `pull` на поверхности. Оба числа
        // переносятся как есть, и все четыре поля набора сходятся точно.
        //
        // Раньше середины у источника не было — сила в ней всегда была нулевой,
        // как у однородного тела, — и падающее наружу поле приходилось
        // подделывать вдвое меньшим телом. Совпадала одна точка из всех: в
        // `GraphicProcessingUnit` у самой середины и у края силы не было вовсе,
        // а на половине радиуса она была вдвое больше положенной.
        foreach (WogFile::kids($this->scene, 'radialforcefield') as $f) {
            $a = $f['attrs'];
            [$cx, $cy] = $this->pair($a, 'center');
            $span = $this->num($a, 'radius', 100);
            $mid = $this->num($a, 'forceatcenter', 0) * self::G;
            $rim = $this->num($a, 'forceatedge', 0) * self::G;
            if ($this->num($a, 'dampeningfactor', 0) > 0) {
                $this->say('сопротивление внутри радиального поля');
            }

            $this->entities[] = ['id' => $this->id('gw'), 'type' => 'gravity-well', 'data' => [
                'x' => WogGeometry::fixed($this->x($cx), 2),
                'y' => WogGeometry::fixed($this->y($cy), 2),
                'pull' => WogGeometry::fixed($rim, 1),
                'core' => WogGeometry::fixed($mid, 1),
                'radius' => WogGeometry::fixed(max(1, $span), 2),
                // Снаружи поля нет вовсе — дальность равна телу, — так что
                // закон убывания ни на что не влияет; оставлен обычный.
                'falloff' => 2,
                // Круглый: в исходнике радиальное поле только такое. Овальный
                // источник — наша возможность, а не перенос.
                'len' => 0.0, 'tilt' => 0.0,
                'range' => WogGeometry::fixed($span, 2),
                // Чистая сила, а не тело: сквозь радиальное поле проходят.
                'solid' => false,
                'movable' => false,
                'signal' => '', 'invert' => false,
                'lines' => 12, 'smoothness' => 0.4,
                'color' => '#8ea6ff', 'fill' => '#2c3450',
            ]];
        }

        // Иные условия конца — замками.
        //
        // Отдельного «уровень кончился» в движке нет и не нужно: цель уже
        // считается шагами, и замок засчитывает шаг ровно как лунка. Значит
        // уровень с целью в один шаг кончается, когда замок открылся.
        //
        // Целевая высота — замок во всю ширину комнаты от её верха до отметки,
        // с пустым ключом: пустой ждёт любой прицепленный шар, а прицепленность
        // и есть «часть постройки». Полосой по самой отметке делать нельзя —
        // быстрый шар проскочил бы её между кадрами.
        foreach (WogFile::kids($this->level, 'targetheight') as $t) {
            $mark = $this->y($this->num($t['attrs'], 'y', 0));
            $this->entities[] = ['id' => $this->id('lck'), 'type' => 'lock', 'data' => [
                'x' => WogGeometry::fixed($this->w / 2, 2),
                // От отметки до верха комнаты. Отметка бывает и выше верха —
                // тогда область просто уходит вверх за край, и это верно:
                // «выше отметки» и значит «выше».
                'y' => WogGeometry::fixed($mark / 2, 2),
                'w' => WogGeometry::fixed($this->w, 2),
                'h' => WogGeometry::fixed(max(1, abs($mark)), 2),
                'key' => '', 'invert' => false, 'delay' => 0.0, 'signal' => '',
                'counts' => true, 'show' => false, 'color' => '#c9a227',
            ]];
            $this->goalFromLocks++;
        }

        // Касание двух тел — замок на месте неподвижного из пары, ждущий
        // подвижное по имени. Имя тела конвертер кладёт телам в `key`, так что
        // ключ уже на месте. Задержка — часть условия: в `ProductLauncher` эти
        // две секунды дают ракете улететь из вида.
        foreach (WogFile::kids($this->level, 'endoncollision') as $e) {
            $a = $e['attrs'];
            $one = $this->geomMiddle[$a['id1'] ?? ''] ?? null;
            $two = $this->geomMiddle[$a['id2'] ?? ''] ?? null;

            if ($one === null || $two === null) {
                $this->say('конец по столкновению: одного из тел нет');

                continue;
            }

            // Кто из пары где: замок встаёт на неподвижном и ждёт подвижное.
            // Место обязано стоять на месте — иначе оно уедет вместе с тем,
            // чего ждёт, и не дождётся никогда. В наборе неподвижен всегда
            // детектор, но полагаемся на признак, а не на имя.
            [$where, $whom] = $one['still'] ? [$one, $a['id2']] : [$two, $a['id1']];

            $this->entities[] = ['id' => $this->id('lck'), 'type' => 'lock', 'data' => [
                'x' => $where['x'], 'y' => $where['y'],
                'w' => WogGeometry::fixed(max(8, $where['w']), 2),
                'h' => WogGeometry::fixed(max(8, $where['h']), 2),
                'key' => (string) $whom,
                'invert' => false,
                'delay' => $this->num($a, 'delay', 0),
                'signal' => '',
                'counts' => true, 'show' => false, 'color' => '#c9a227',
            ]];
            $this->goalFromLocks++;
        }

        // Кнопка внутри уровня — это прохождение, а не меню.
        //
        // Из пятнадцати кнопок набора четырнадцать стоят на экранах карты,
        // которые мы уровнями не считаем. Пятнадцатая — иконка в `MOM`: строишь
        // башню из блоков, дотягиваешься, щёлкаешь, уровень пройден. Команда
        // `onclick` зашита в игру и не переносится, а вот сам щелчок и есть
        // цель, поэтому кнопке ставится счёт шага.
        //
        // Условие «дотянулся И щёлкнул» собирается из двух сущностей, и ни одна
        // не знает другую: замок поверх кнопки держит сигнал, пока рядом блок,
        // а кнопка этим сигналом включается.
        foreach (WogFile::kids($this->scene, 'buttongroup') as $group) {
            foreach (WogFile::kids($group, 'button') as $b) {
                $this->placeButton($b['attrs']);
            }
        }

        foreach (WogFile::kids($this->scene, 'button') as $b) {
            $this->placeButton($b['attrs']);
        }


        //
        // Их в наборе пятнадцать: четырнадцать на экранах карты островов и в
        // главном меню, которые мы вообще не считаем уровнями, и одна в MOM —
        // иконка, запускающая зашитый в игру разговор с мамой. То есть кнопок
        // «от уровня» в исходнике нет ни одной: это устройство чужого меню, а
        // меню у нас своё.
        //
        // Конец по сообщению. Само сообщение поднимает зашитый в игру
        // сценарий, и переносить его неоткуда — но кончиться уровню есть чем,
        // если мы уже поставили то, что засчитывает шаг.
        //
        // В `MOM` это кнопка: строишь башню, дотягиваешься, щёлкаешь по иконке.
        //
        // В `Deliverance` конец наступает, когда таблетку довозят донизу и её
        // разбивает лопающая поверхность. Сообщения в данных нет, но есть обе
        // его половины: шар с начинкой и поверхность с тегом `ballbuster`. Раз
        // таких шара и поверхности в уровне ровно по одному, связать их не
        // домысел, а прочтение — другого повода поднять сообщение в уровне нет.
        //
        // Поэтому шаг к цели засчитывается прямо на разбивании: отдельного
        // «уровень кончился» в движке нет и не нужно, цель считается шагами.
        // Если поводов больше одного, связывать наугад нельзя — тогда честнее
        // сказать, что не перенесено.
        //
        // Лопающихся шаров в уровне бывает много: в `Deliverance` кроме
        // таблетки лопаются сорок пять `Bit`. Отличает её то, что с ней вообще
        // ничего нельзя сделать — ни взять в руку, ни засосать в трубу, ни
        // прицепить к конструкции. Такой шар не средство, а груз: единственное,
        // что с ним может случиться, — доехать вниз и разбиться.
        if (WogFile::kids($this->level, 'endonmessage') !== [] && $this->goalFromLocks === 0) {
            $pills = [];

            foreach ($this->entities as $i => $e) {
                if ($e['type'] !== 'game-ball') {
                    continue;
                }

                $kind = $this->kinds[$this->kindOf[$e['id']] ?? ''] ?? null;

                if ($kind === null || !($kind['poppable'] ?? false)) {
                    continue;
                }

                $carried = !($kind['draggable'] ?? true)
                    && !($e['data']['suckable'] ?? $kind['suckable'] ?? true)
                    && ($kind['maxLinks'] ?? 0) === 0;

                if ($carried) {
                    $pills[] = $i;
                }
            }

            $bursting = 0;

            foreach ($this->entities as $e) {
                if (in_array($e['type'], ['terrain', 'object'], true) && ($e['data']['bursting'] ?? false)) {
                    $bursting++;
                }
            }

            if (count($pills) === 1 && $bursting === 1) {
                $this->entities[$pills[0]]['data']['popCounts'] = true;
                $this->goalFromLocks++;
            } else {
                $this->say('конец по сообщению — кончить уровень нечем');
            }
        }
    }

    /**
     * The balls, and the structure they may already be standing in.
     *
     * Almost everything about a ball belongs to its type rather than to this
     * one: size, weight, strand strength, parts, waves. So the type is built
     * once and kept as an asset, and the level carries a reference plus the
     * little that is this ball's own — where it stands, whether it is asleep,
     * and what it is tied to.
     *
     * An anchor in the original is the same ball described by the same file,
     * merely pinned. Splitting it off as its own kind would be an invention,
     * and it was exactly that invention that used to break the links: the list
     * looks for a neighbour of its own type.
     */
    /**
     * Виды, на которые ссылается начинка, тоже должны попасть в пакет.
     *
     * Начинка называет ассет, а ассетом становится только тот вид, который
     * где-то поставлен в уровне. `BeautyProductEye` нигде не поставлен — он
     * появляется лишь из лопнувшей `Beauty`, — поэтому ссылка на него вела бы в
     * пустоту: шар лопнул бы и не родил ничего, молча.
     *
     * Рекурсивно, потому что начинка бывает вложенной: `UndeletePill` содержит
     * восемьдесят `UndeletePillFizz`, а каждая из них — ещё четыре `Spam`.
     */
    private function deeper(string $type): void
    {
        foreach ($this->fillings[$type] ?? [] as $row) {
            $name = null;

            foreach (array_keys($this->ballDefs) as $known) {
                if ('wog-ball-' . mb_strtolower($known) === $row['asset']) {
                    $name = $known;

                    break;
                }
            }

            if ($name === null || isset($this->kinds[$name])) {
                continue;
            }

            $this->kinds[$name] = WogBallKind::build(
                $this->ballDefs[$name],
                $this->res['images'],
                $this->res['sounds'],
                $this->root,
                $this->mats,
            );
            $this->fillings[$name] = WogBallKind::births($this->ballDefs[$name]['attrs']);
            $this->deeper($name);
        }
    }

    private function balls(): void
    {
        // Кого принимает труба. Читается здесь, а не там, где труба строится:
        // отбор ложится на размещения шаров, а они собираются раньше.
        foreach (WogFile::kids($this->level, 'levelexit') as $e) {
            $filter = trim($e['attrs']['filter'] ?? '');

            // Пустой отбор — «принимает всех», и таких тридцать шесть против
            // семи настоящих.
            if ($filter === '') {
                continue;
            }

            $this->takes ??= [];

            foreach (array_filter(array_map('trim', explode(',', $filter))) as $kind) {
                $this->takes[$kind] = true;
            }
        }

        $byWogId = [];

        foreach (WogFile::kids($this->level, 'BallInstance') as $b) {
            $type = $b['attrs']['type'] ?? '';
            $def = $this->ballDefs[$type] ?? null;

            if ($def === null) {
                $this->say("определение шара отсутствует ({$type})");

                continue;
            }

            $a = $def['attrs'];
            $shape = explode(',', $a['shape'] ?? 'circle,30');

            $known = isset($this->kinds[$type]);
            $this->kinds[$type] ??= WogBallKind::build($def, $this->res['images'], $this->res['sounds'], $this->root, $this->mats);
            $this->fillings[$type] ??= WogBallKind::births($def['attrs']);
            $this->deeper($type);

            if (!$known) {
                $strandOf = WogFile::kids($def, 'strand')[0] ?? null;

                // Второй предел длины — не длина, а способ строить: шар тратится
                // на перемычку между двумя уже прицепленными и исчезает.
                // Способа этого у нас нет по решению автора («можно без этого»),
                // и сказано это один раз на ассет, а не на каждый из двух с
                // лишним тысяч: теряется возможность, а не содержимое уровня.
                if ($strandOf !== null && isset($strandOf['attrs']['maxlen1'])) {
                    $this->say('шар не тратится на перемычку — всегда становится узлом');
                }
            }

            foreach ($this->kinds[$type]['parts'] as $part) {
                if ($part['src'] !== '') {
                    $this->media[$part['src']] = $part['src'];
                }
            }

            $own = [
                'id' => $this->id('b'),
                'type' => 'game-ball',
                'asset' => 'wog-ball-' . mb_strtolower($type),
                // Ассет шара — группа, если у шара есть начинка: там ещё
                // рождение и его содержимое. Участник называется всегда, а не
                // только для групп: иначе он появлялся бы у одних шаров и
                // пропадал у других, и пакет читался бы хуже, чем пишется.
                'member' => 'wog-ball-' . mb_strtolower($type) . '-e',
                'data' => [
                    'x' => $this->x($this->num($b['attrs'], 'x', 0)),
                    'y' => $this->y($this->num($b['attrs'], 'y', 0)),
                    // Being asleep is about the instance: the same type lies
                    // asleep in one level and walks about in another.
                    'asleep' => ($b['attrs']['discovered'] ?? 'true') === 'false',
                    'links' => [],
                ]
                    // Труба, которая принимает не всех, — это свойство шара, и
                    // притом свойство ЭТОГО шара, а не его вида: обычный шар
                    // уезжает в трубу в двадцати уровнях и не уезжает в двух.
                    // Размещение хранит отличия от вида, так что сказать это
                    // можно ровно там, где оно верно.
                    //
                    // Отдельного поля не заводим: «эта труба меня не примет» и
                    // «меня нельзя засосать» с места игрока неразличимы, а два
                    // поля с одинаковым видимым действием — это выбор, который
                    // автору не с чем делать. Разошлись бы они только в уровне
                    // с двумя трубами и разным отбором; таких в наборе нет ни
                    // одного, а если появится — тогда и понадобится второе
                    // поле, названное по своему поводу.
                    + ($this->takes === null || isset($this->takes[$type]) ? [] : ['suckable' => false])
                    // Поворот — свойство размещения, как и место: в
                    // `RoadBlocks` одни и те же бруски лежат под разными
                    // углами. Градусы, как и в исходнике, но знак обратный:
                    // ось Y у нас смотрит вниз.
                    + ((float) ($b['attrs']['angle'] ?? 0) !== 0.0
                        ? ['angle' => self::fixed(-$this->num($b['attrs'], 'angle', 0), 2)]
                        : []),
            ];

            // Какого вида это размещение. Нужно условиям конца уровня: они
            // разбираются позже, когда у сущности остался только ассет.
            $this->kindOf[$own['id']] = $type;
            $this->entities[] = $own;
            $byWogId[$b['attrs']['id'] ?? ''] = count($this->entities) - 1;

            // Раздатчик. В исходнике это признак у шара — «по щелчку выдаёт
            // шар такого вида», — а у нас отдельная сущность: образец у неё
            // внутри, и по нему делается копия за копией.
            //
            // Вешается на сам шар родством, поэтому едет за ним и нажимается
            // там же, где он. Образец — ссылка на ассет выдаваемого вида, а не
            // его копия.
            //
            // Предел живых копий — решение автора: два. В исходнике предела
            // нет вовсе, и поставить что-то приходится: без него раздатчик
            // ломает любую цель, с единицей уровень становится строже
            // оригинала.
            $gives = trim($a['spawn'] ?? '');

            if ($gives !== '' && isset($this->ballDefs[$gives])) {
                $this->kinds[$gives] ??= WogBallKind::build(
                    $this->ballDefs[$gives],
                    $this->res['images'],
                    $this->res['sounds'],
                    $this->root,
                    $this->mats,
                );
                $this->fillings[$gives] ??= WogBallKind::births($this->ballDefs[$gives]['attrs']);
                $this->deeper($gives);

                $out = $this->id('dsp');
                $of = 'wog-ball-' . mb_strtolower($gives);
                $this->entities[] = ['id' => $out, 'type' => 'dispenser', 'parent' => $own['id'], 'data' => [
                    'x' => $own['data']['x'], 'y' => $own['data']['y'],
                    // Радиус нажатия — радиус самого шара, но не меньше двенадцати:
                    // по крошечной иконке иначе было бы не попасть. Берётся из
                    // вида, а не из размещения: размещение хранит только
                    // отличия, и размера в нём нет.
                    'r' => WogGeometry::fixed(max(12.0, (float) ($this->kinds[$type]['r'] ?? 26)), 2),
                    'limit' => 2,
                    'color' => '#c9a227',
                ]];
                // Ключ у выдаваемого — название его материала.
                //
                // Ключ вообще-то про «кто ты», а не «какой ты», и однажды я
                // уже ошибся, проставив его ассетам. Здесь иначе: замок у
                // иконки ждёт ЛЮБОЙ блок, то есть ему нужен именно разряд, а
                // материал — собственное слово набора для него. У обоих
                // выдаваемых окон он один: `BlockBall`.
                //
                // И стоит ключ на размещении, а не на ассете: помечены ровно
                // те четыре штуки, которые раздают, а не все шары такого вида.
                $stuff = mb_strtolower((string) ($this->ballDefs[$gives]['attrs']['material'] ?? ''));
                $this->entities[] = [
                    'id' => $this->id('giv'), 'type' => 'game-ball', 'parent' => $out,
                    'asset' => $of, 'member' => $of . '-e',
                    'data' => ['x' => $own['data']['x'], 'y' => $own['data']['y'], 'key' => $stuff],
                ];
                $this->handed[$stuff] = true;
            }

            // Признаков шара, которые некуда деть, здесь больше нет: липкость
            // уехала в `sticky`/`stickyWhen`, начинка в рождение, `spawn` в
            // раздатчик, `stuckattachment` в `anchorableStuck`. Список пуст, и
            // пустой обход убран — он врал бы, что проверка ещё идёт.

            $strand = WogFile::kids($def, 'strand')[0] ?? null;

        }

        // The structure the level starts with.
        foreach (WogFile::kids($this->level, 'Strand') as $st) {
            $a = $byWogId[$st['attrs']['gb1'] ?? ''] ?? null;
            $b = $byWogId[$st['attrs']['gb2'] ?? ''] ?? null;

            if ($a === null || $b === null) {
                $this->say('связь ссылается на несуществующий шар');

                continue;
            }

            $this->entities[$a]['data']['links'][] = $this->entities[$b]['id'];
            $this->entities[$b]['data']['links'][] = $this->entities[$a]['id'];
        }
    }

    /**
     * The pipe, and the exit that turned out to be the same thing.
     *
     * The original records an exit separately, and I kept it in the report as
     * something not carried over — forty-three of them across three quarters of
     * the levels. Measuring settled it: in all forty-three its position matches
     * the pipe's FIRST vertex, its mouth, and the radius is 75 in every single
     * one, so it says nothing about the level. The pipe is the exit.
     */
    private function pipeAndExit(): void
    {
        $mouths = [];

        foreach (WogFile::kids($this->level, 'pipe') as $p) {
            $pts = [];

            foreach (WogFile::kids($p, 'Vertex') as $v) {
                $pts[] = $this->p($this->num($v['attrs'], 'x', 0), $this->num($v['attrs'], 'y', 0));
            }

            if (count($pts) >= 2) {
                $mouths[] = $pts[0];
                $this->entities[] = ['id' => $this->id('pipe'), 'type' => 'pipe', 'data' => [
                    'points' => $pts, 'radius' => 30, 'power' => 1,
                    'color' => '#4c93c4', 'inner' => '#0d1a24',
                ]];
            }
        }

        foreach (WogFile::kids($this->level, 'levelexit') as $e) {
            // Пустой filter="" — это «пускает всех», и таких 36 против 7
            // настоящих. В JS пустая строка ложна и отсекалась сама; здесь
            // `isset` про неё истинно, и без явной проверки отчёт разбухал
            // впятеро жалобами на то, чего нет.
            [$ex, $ey] = $this->pair($e['attrs'], 'pos');
            $far = INF;

            foreach ($mouths as [$mx, $my]) {
                $far = min($far, hypot($mx - $this->x($ex), $my - $this->y($ey)));
            }

            // Should a set turn up where the exit is not on the mouth, we hear
            // about it instead of carrying a silent mistake.
            if ($far > 5) {
                $this->say('выход уровня стоит не на устье трубы');
            }
        }
    }

    private function fires(): void
    {
        foreach (WogFile::kids($this->level, 'fire') as $f) {
            $this->entities[] = ['id' => $this->id('fire'), 'type' => 'fire', 'data' => [
                'x' => $this->x($this->num($f['attrs'], 'x', 0)),
                'y' => $this->y($this->num($f['attrs'], 'y', 0)),
                'r' => max(10, $this->num($f['attrs'], 'radius', 60)),
                'signal' => '', 'invert' => false,
                'show' => true, 'color' => '#ff9a3c',
            ]];
        }
    }

    private function music(): void
    {
        foreach (['music' => 'музыка', 'loopsound' => 'фоновый звук'] as $tag => $label) {
            foreach (WogFile::kids($this->level, $tag) as $m) {
                $rel = $this->res['sounds'][$m['attrs']['id'] ?? ''] ?? null;

                if ($rel === null || !is_file($this->root . '/' . $rel . '.ogg')) {
                    $this->say("{$label}: файла нет в наборе");

                    continue;
                }

                $this->entities[] = ['id' => $this->id('snd'), 'type' => 'sound', 'data' => [
                    'x' => $this->w / 2, 'y' => 40,
                    'src' => $rel . '.ogg',
                    'volume' => $tag === 'music' ? 0.6 : 0.4,
                    'loop' => true,
                    'signal' => '', 'invert' => false,
                    'show' => false, 'color' => '#8fb36a',
                ]];
                $this->media[$rel] = $rel . '.ogg';
            }
        }
    }

    /**
     * There are two cameras, one for 4:3 and one for widescreen. We take the
     * wide one: the wob window is 1600x900, which is wide too.
     */
    private function camera(): void
    {
        foreach (WogFile::kids($this->level, 'camera') as $c) {
            if (($c['attrs']['aspect'] ?? '') !== 'widescreen') {
                continue;
            }

            $pois = WogFile::kids($c, 'poi');

            // Конечный вид у семнадцати уровней задан не отдельными атрибутами,
            // а последней точкой пути: у камеры там стоит один только `aspect`.
            // Раньше такая камера отбрасывалась целиком и окно вставало по
            // умолчанию — при том, что взять конечный вид было откуда, и код
            // ниже и так считает последнюю точку конечной.
            $last = $pois === [] ? null : end($pois);

            $zoom = $this->num($c['attrs'], 'endzoom', 0)
                ?: ($last === null ? 0.0 : $this->num($last['attrs'], 'zoom', 0));

            if ($zoom === 0.0) {
                $this->say('камера без конечного вида — окно встанет по умолчанию');

                continue;
            }

            [$ex, $ey] = isset($c['attrs']['endpos']) || $last === null
                ? $this->pair($c['attrs'], 'endpos')
                : $this->pair($last['attrs'], 'pos');
            $shots = [];

            // A point there records the time it takes to fly TO it. Ours
            // records the time from it to the next, so the list shifts by one:
            // the original's last point is the final view, and its travel time
            // belongs to the one before it.
            for ($i = 0; $i + 1 < count($pois); $i++) {
                [$px, $py] = $this->pair($pois[$i]['attrs'], 'pos');
                $pz = $this->num($pois[$i]['attrs'], 'zoom', 0) ?: $zoom;
                $shots[] = [
                    'x' => $this->x($px), 'y' => $this->y($py),
                    'w' => WogGeometry::whole(self::WIDE_W / $pz),
                    'pause' => $this->num($pois[$i]['attrs'], 'pause', 0),
                    'travel' => $this->num($pois[$i + 1]['attrs'], 'traveltime', 0),
                ];
            }

            $this->entities[] = ['id' => $this->id('cam'), 'type' => 'camera', 'data' => [
                'x' => $this->x($ex), 'y' => $this->y($ey),
                'w' => WogGeometry::whole(self::WIDE_W / $zoom),
                'h' => WogGeometry::whole(self::WIDE_H / $zoom),
                'shots' => $shots,
                'signal' => '',
                'show' => false, 'color' => '#e8c46a',
            ]];
        }
    }

    /**
     * Предел углового ускорения из предела силы: α = M / I.
     *
     * Сила приложена по ободу, плечо — половина большей стороны. Момент
     * инерции как у прямоугольной пластины вокруг середины, m(w² + h²)/12:
     * тела, к которым цепляют двигатель, прямоугольные.
     *
     * Множитель силы — шесть, из согласованной тройки (длина и время 1:1,
     * ускорение ×180, масса ×1/30). НЕ из предела разрыва связи: там ×43,
     * потому что в нём сидит поправка на разницу решателей, и в эту формулу
     * она не относится. Проверено: со сведённым пределом разрыва половина
     * построек в наборе рассыпается, то есть поправка нужна именно там.
     *
     * @param array{x: float, y: float, at: int, w: float, h: float, mass: float} $body
     */
    private static function spinLimit(float $maxforce, array $body): float
    {
        $torque = $maxforce * 6.0 * (max($body['w'], $body['h']) / 2);
        $inertia = $body['mass'] * ($body['w'] ** 2 + $body['h'] ** 2) / 12;

        return $inertia > 0 ? WogGeometry::fixed($torque / $inertia, 3) : 60.0;
    }

    /**
     * Картинка по имени ресурса: путь и настоящий размер.
     *
     * @return array{src: string, w: float, h: float}|null
     */
    private function imageOf(string $id): ?array
    {
        $rel = $this->res['images'][$id] ?? null;

        if ($rel === null) {
            return null;
        }

        $size = WogFile::pngSize($this->root . '/' . $rel . '.png');

        if ($size === null) {
            return null;
        }

        $this->media[$id] = $rel . '.png';

        return ['src' => $rel . '.png', 'w' => (float) $size['w'], 'h' => (float) $size['h']];
    }

    // --- мелочи ---------------------------------------------------------------


    /** @return array<string, mixed> */
    private function picture(
        float $x,
        float $y,
        float $w,
        float $h,
        float $rot,
        float $opacity,
        int $depth,
        float $tint,
        string $src,
        float $tileW = 0,
        float $tileH = 0,
    ): array {
        return [
            'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'rot' => $rot, 'opacity' => $opacity, 'depth' => $depth,
            // Список источников остаётся пустым: выбирать не из чего. Заводится
            // он у следа гибели, где пятен несколько, — а украшение уровня
            // всегда одно и то же.
            'src' => $src, 'srcs' => [], 'fit' => 'none', 'tint' => $tint,
            'tileW' => $tileW, 'tileH' => $tileH,
            'flipX' => false, 'flipY' => false,
            'anim' => [], 'animDur' => 0, 'animLoop' => true,
        ];
    }

    /**
     * Colourise is a per-channel multiplier. Every value in the set is the same
     * across channels, which means it is brightness and nothing more.
     */
    private function tintOf(?string $v): float
    {
        if ($v === null || $v === '') {
            return 1.0;
        }

        $parts = array_map('floatval', explode(',', $v));

        return count($parts) === 3 ? self::fixed(($parts[0] + $parts[1] + $parts[2]) / 3 / 255, 3) : 1.0;
    }

    /**
     * Rounding that matches the other side exactly.
     *
     * PHP's round() rounds a half up after nudging the value; JavaScript's
     * toFixed goes by the binary representation. On 324.025 the first says
     * 324.03 and the second 324.02 — one hundredth apart on one bush in one
     * level, and enough to make a content hash differ.
     *
     * sprintf agrees with toFixed, so everything that has to line up goes
     * through here rather than through round() scattered about.
     */
    private static function fixed(float $v, int $places): float
    {
        return WogGeometry::fixed($v, $places);
    }

    private function id(string $prefix): string
    {
        return $prefix . (++$this->n);
    }

    private function say(string $what, int $count = 1): void
    {
        ($this->miss)($what, $count);
    }

    /** @param array<string, string> $a */
    private function num(array $a, string $key, float $default): float
    {
        return isset($a[$key]) && $a[$key] !== '' && is_numeric($a[$key]) ? (float) $a[$key] : $default;
    }

    /** @param array<string, string> $a */
    private function numOrNull(array $a, string $key): ?float
    {
        return isset($a[$key]) && $a[$key] !== '' && is_numeric($a[$key]) ? (float) $a[$key] : null;
    }

    /**
     * @param array<string, string>   $a
     * @param array{0: float, 1: float} $default
     *
     * @return array{0: float, 1: float}
     */
    private function pair(array $a, string $key, array $default = [0.0, 0.0]): array
    {
        if (!isset($a[$key]) || $a[$key] === '') {
            return $default;
        }

        $parts = array_map('floatval', explode(',', $a[$key]));

        return [$parts[0] ?? $default[0], $parts[1] ?? $default[1]];
    }

    /**
     * A value that may be a span. One number means no spread at all.
     *
     * @param array<string, string> $a
     *
     * @return array{0: float, 1: float}
     */
    private function span(array $a, string $key, float $default): array
    {
        if (!isset($a[$key]) || $a[$key] === '') {
            return [$default, $default];
        }

        $parts = array_map('floatval', explode(',', $a[$key]));

        return count($parts) > 1 ? [$parts[0], $parts[1]] : [$parts[0], $parts[0]];
    }
}
