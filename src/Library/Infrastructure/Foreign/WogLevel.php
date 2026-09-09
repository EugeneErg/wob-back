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

    /** Layer depths, named rather than imported, for the same reason as the rest. */
    private const BACKGROUND = -60;
    private const MIDGROUND = 0;

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
     * @return array{level: array<string, mixed>, media: array<string, string>, kinds: array<string, array<string, mixed>>}
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

        return [
            'level' => [
                'id' => 'wog-' . mb_strtolower($this->name),
                'name' => $this->name,
                'width' => WogGeometry::whole($this->w),
                'height' => WogGeometry::whole($this->h),
                'gravity' => $this->gravity,
                'goal' => (int) $this->num($this->level['attrs'], 'ballsrequired', 1),
                'hot' => [],
                'entities' => $this->entities,
            ],
            'media' => $this->media,
            'kinds' => $this->kinds,
        ];
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

            if ($this->num($f['attrs'], 'dampeningfactor', 0) > 0) {
                $this->say('сопротивление среды на весь уровень (dampeningfactor)');
            }
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
                    ), $g['attrs']),
                    'circle' => $this->solid(WogGeometry::circlePoints(
                        $gx + $this->num($a, 'x', 0),
                        $gy + $this->num($a, 'y', 0),
                        $this->num($a, 'radius', 0),
                        $this->p(...),
                    ), $g['attrs']),
                    default => null,
                };

                // Fixed pieces do not need the assembly: they do not move
                // anyway, and the extra parenthood only muddles the tree.
                if ($made === null || $made['static']) {
                    continue;
                }

                if ($owner === null) {
                    $owner = $made['id'];
                } else {
                    $this->entities[$made['at']]['parent'] = $owner;
                }
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

        if (isset($attrs['rotspeed'])) {
            $this->say('собственное вращение тела (rotspeed)');
        }

        $paint = isset($attrs['invisible'])
            ? ['fill' => 'transparent', 'edge' => 'transparent']
            : [];

        if ($static) {
            $id = $this->id('t');
            $data = [
                'points' => $pts, 'smoothness' => $m['smoothness'],
                'walkable' => true, 'deadly' => false, 'detaching' => false, 'stopsign' => false,
                'fill' => '#2a3326', 'edge' => '#66804f',
            ];
        } else {
            $id = $this->id('o');
            $data = [
                'points' => $pts, 'mass' => $this->num($attrs, 'mass', 6),
                'smoothness' => $m['smoothness'], 'restitution' => $m['restitution'],
                'static' => false,
                'walkable' => true, 'deadly' => false, 'detaching' => false, 'stopsign' => false,
                'pivots' => [],
                'fill' => '#5c5346', 'edge' => '#8d7f68',
            ];
        }

        $this->entities[] = [
            'id' => $id,
            'type' => $static ? 'terrain' : 'object',
            'data' => array_merge($data, $surface, $paint),
        ];

        $made = ['id' => $id, 'static' => $static, 'at' => count($this->entities) - 1];

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
        foreach (WogFile::kids($this->scene, 'hinge') as $h) {
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
                if ($b !== null && !$a['static'] && !$b['static']) {
                    $this->entities[$b['at']]['parent'] = $a['id'];
                }

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
                'depth' => 65,
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
        )];

        if (isset($a['anim'])) {
            $this->animate($a, count($this->entities) - 1);
        }

        if (isset($a['tilex']) || isset($a['tiley'])) {
            $this->say('замощение картинкой (tilex/tiley)');
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
            'opacity' => 1,
            'depth' => $this->layerOf($depth),
        ]];

        if (isset($a['dampening'])) {
            $this->say('затухание частиц (шкала не сверена)');
        }

        if (($a['additive'] ?? '') === 'true') {
            $this->say('складывающее наложение частиц');
        }

        if (($a['directed'] ?? '') === 'true') {
            $this->say('частица, повёрнутая по движению');
        }
    }

    // --- поля, вода, трубы, огонь, звук, камера --------------------------------

    private function fields(): void
    {
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

            if (($a['geomonly'] ?? '') === 'true') {
                $this->say('поле, действующее только на геометрию (geomonly)');
            }

            if (($a['enabled'] ?? '') === 'false') {
                $this->say('поле, выключенное на старте (enabled=false)');
            }

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

                if ($density <= 0) {
                    $this->say('вода без выталкивающей силы — в ней тонут');
                }

                $damp = $this->num($a, 'dampeningfactor', 0);

                if ($damp === 0.0) {
                    $this->say('вода без затухания — взято значение по умолчанию');
                }

                $this->entities[] = ['id' => $this->id('pool'), 'type' => 'pool', 'data' => [
                    'x' => $cx, 'y' => $cy, 'w' => $w0, 'h' => $h0,
                    'density' => max(0, $density),
                    'drag' => $damp ?: 4,
                    'waves' => true,
                    'color' => '#2f6f8f', 'surface' => '#9fd8ef',
                ]];

                continue;
            }

            [$fx, $fy] = $this->pair($a, 'force');
            $this->entities[] = ['id' => $this->id('ff'), 'type' => 'field', 'data' => [
                'x' => $cx, 'y' => $cy, 'w' => $w0, 'h' => $h0,
                'ax' => self::fixed($fx * self::G, 1),
                'ay' => self::fixed(-$fy * self::G, 1),
                // antigrav there means "pays no attention to weight", that is,
                // an acceleration. Without it this is a force, and heavy things
                // resist it more.
                'byMass' => ($a['antigrav'] ?? '') !== 'true',
                'damping' => $this->num($a, 'dampeningfactor', 0),
                'signal' => '', 'invert' => false,
                'show' => false, 'color' => '#7fb6cc',
            ]];

            if ($this->num($a, 'dampeningfactor', 0) !== 0.0) {
                $this->say('затухание поля взято в исходных единицах — шкала не сверена');
            }
        }

        foreach ([
            'button' => 'кнопка',
            'buttongroup' => 'группа кнопок',
            'motor' => 'мотор сцены',
            'radialforcefield' => 'радиальное поле (есть аналог, но не перенесено)',
        ] as $tag => $note) {
            $c = count(WogFile::kids($this->scene, $tag));

            if ($c > 0) {
                $this->say($note, $c);
            }
        }

        foreach ([
            'endoncollision' => 'конец по столкновению',
            'endonmessage' => 'конец по сообщению',
            'targetheight' => 'целевая высота',
        ] as $tag => $note) {
            $c = count(WogFile::kids($this->level, $tag));

            if ($c > 0) {
                $this->say($note, $c);
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
    private function balls(): void
    {
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

            if (($shape[0] ?? 'circle') !== 'circle') {
                $w = (float) ($shape[1] ?? 30);
                $h = (float) ($shape[2] ?? $w);
                // A square is described by a circle well enough; a bar is not,
                // and saying so separately keeps one report line from hiding
                // two quite different cases.
                $this->say(max($w, $h) / min($w, $h) > 1.5
                    ? "вытянутый шар {$w}×{$h} — вписан в круг по короткой стороне"
                    : 'квадратный шар — вписан в круг');
            }

            $this->kinds[$type] ??= WogBallKind::build($def, $this->res['images'], $this->res['sounds'], $this->root);

            foreach ($this->kinds[$type]['parts'] as $part) {
                if ($part['src'] !== '') {
                    $this->media[$part['src']] = $part['src'];
                }
            }

            $own = [
                'id' => $this->id('b'),
                'type' => 'game-ball',
                'asset' => 'wog-ball-' . mb_strtolower($type),
                'data' => [
                    'x' => $this->x($this->num($b['attrs'], 'x', 0)),
                    'y' => $this->y($this->num($b['attrs'], 'y', 0)),
                    // Being asleep is about the instance: the same type lies
                    // asleep in one level and walks about in another.
                    'asleep' => ($b['attrs']['discovered'] ?? 'true') === 'false',
                    'links' => [],
                ],
            ];

            $this->entities[] = $own;
            $byWogId[$b['attrs']['id'] ?? ''] = count($this->entities) - 1;

            foreach ([
                'sticky' => 'липкость шара', 'stickyattached' => 'липкость шара',
                'stickyunattached' => 'липкость шара', 'stuckattachment' => 'липкость шара',
                'contains' => 'шар с начинкой (contains)', 'spawn' => 'шар, порождающий другой',
                'material' => 'материал шара',
            ] as $attr => $note) {
                if (isset($a[$attr]) && $a[$attr] !== '0') {
                    $this->say($note);
                }
            }

            $strand = WogFile::kids($def, 'strand')[0] ?? null;

            // Strand damping is left out on purpose: the scales do not line up
            // — dampfac reaches 1.9 there while ours is a fraction of one — and
            // an invented coefficient would be worse than an honest gap,
            // because it would quietly change how a structure behaves.
            if ($strand !== null && isset($strand['attrs']['dampfac'])) {
                $this->say('гашение связи (шкалы не сопоставимы)');
            }

            if ($strand !== null && isset($strand['attrs']['maxlen1'])) {
                $this->say('вторая дальность связи (между прицепленными)');
            }
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
            if (($e['attrs']['filter'] ?? '') !== '') {
                $this->say('выход пускает только некоторые виды шаров');
            }

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

            $zoom = $this->num($c['attrs'], 'endzoom', 0);

            if ($zoom === 0.0) {
                $this->say('камера без конечного вида — окно встанет по умолчанию');

                continue;
            }

            [$ex, $ey] = $this->pair($c['attrs'], 'endpos');
            $pois = WogFile::kids($c, 'poi');
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
    ): array {
        return [
            'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'rot' => $rot, 'opacity' => $opacity, 'depth' => $depth,
            'src' => $src, 'fit' => 'none', 'tint' => $tint,
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
