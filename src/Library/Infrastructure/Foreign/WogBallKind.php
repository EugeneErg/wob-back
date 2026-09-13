<?php

declare(strict_types=1);

namespace Wob\Library\Infrastructure\Foreign;

/**
 * One ball type, as the settings that fully describe it.
 *
 * A type is built once and stored as an asset; the balls in a level name it and
 * carry only where they stand. There are 54 types in the reference set and 2706
 * balls, so copying the settings into every ball would repeat each set about
 * fifty times — nearly four megabytes of parts and sines alone, and "change the
 * eye on every ball of this type" would mean editing fifty entities by hand.
 *
 * Nothing here reaches into the engine for defaults. Every field is named with
 * a value chosen on purpose, because a saved level has to be complete: a field
 * that arrives from a default changes when somebody edits the engine, silently
 * and without the author's consent.
 */
final class WogBallKind
{
    /**
     * Units are pinned to `common`.
     *
     * An ordinary ball in the original and the wob defaults describe the same
     * body, so its numbers are the anchor: mass 30, climbspeed 2.0, walkforce
     * 500, springconstmax 9 become 1, 95, 1400, 1600.
     *
     * The breaking force is scaled by 26000/600, and 600 is the default used
     * when a ball has no strand at all — not common's own value, which is 1000.
     * The comment on the other side said otherwise and had it wrong; the
     * numbers were always right, only the explanation was not.
     *
     * Mass in particular cannot be skipped. It sets the load a strand works
     * under, and without the conversion a structure comes out thirty times
     * heavier than the stiffness was chosen for — which is exactly how it
     * looked when it collapsed.
     */
    /**
     * Открыт наружу: этим же множителем переносится масса подвижной геометрии.
     *
     * Держать его тут, а не копией в двух местах, — потому что это одна
     * величина. Разойдись они, тело и шар оказались бы в разных единицах, и
     * заметно это стало бы не сразу: конструкция просто вела бы себя странно
     * под тяжёлым ящиком.
     */
    public const MASS = 1 / 30;
    private const CLIMB = 95 / 2.0;
    private const FORCE = 1400 / 500;
    private const SPRING = 1600 / 9;
    private const BREAK = 26000 / 600;
    /**
     * Запуск Bit и Pilot.
     *
     * В описании формата `fling="{длина стрелки},{множитель силы}"`, и у обоих
     * стоит `200,2.5`; там же сказано, что множитель считает силу, поэтому
     * лёгкий шар улетает дальше тяжёлого. Значит скорость — это оттяжка,
     * умноженная на множитель и делённая на массу; неизвестен один общий
     * множитель, и его в описании нет.
     *
     * Взят по уровню `HelloWorld` — первому, где игрок вообще запускает шар.
     * Перелёт с левого островка на правый там около 500 px, при нашей тяжести
     * это примерно 950 px/с под 45°. Отсюда 1.9: полная оттяжка в 200 при
     * множителе 2.5 даёт ровно эти 950 шару обычной массы. Замер на месте:
     * такой запуск уносит шар на 580 px, то есть с запасом.
     */
    private const FLING = 1.9;

    /**
     * The original has thirteen states; we have twelve poses.
     *
     * `tank` is missing on purpose: it is the collection screen between levels,
     * not a state a ball is in inside the world, and no part in the set is
     * visible only there.
     */
    private const POSES = [
        'attached' => 'built', 'climbing' => 'climb', 'detaching' => 'pull',
        'dragging' => 'drag', 'falling' => 'fall', 'pipe' => 'pipe',
        'sleeping' => 'asleep', 'standing' => 'stand', 'stuck' => 'stuck',
        'stuck_attached' => 'stuckBuilt', 'stuck_detaching' => 'stuckPull',
        'walking' => 'walk',
    ];

    /**
     * @param array<string, mixed>  $def   the ball's own definition
     * @param array<string, string> $images resource id to path
     * @param array<string, string> $sounds resource id to path
     *
     * @param array<string, array{smoothness: float, restitution: float}> $mats
     *
     * @return array<string, mixed>
     */
    public static function build(array $def, array $images, array $sounds, string $root, array $mats = []): array
    {
        $a = $def['attrs'];

        // shape="circle,d" или "rectangle,w,h": диаметр, а не радиус.
        $parts = explode(',', $a['shape'] ?? 'circle,30');
        $kind = trim($parts[0] ?? 'circle');
        $bw = (float) ($parts[1] ?? 30);
        $bh = (float) ($parts[2] ?? $bw);
        // Скругление у прямоугольного тела — четверть меньшей стороны: у
        // квадрата мягкий угол, у бруска 200×50 почти капсула, и ни в одном
        // случае скругление не съедает форму. Отдельного числа в исходнике
        // нет, а без скругления углы цеплялись бы за геометрию намертво.
        $round = $kind === 'circle' ? $bw / 2 : min($bw, $bh) / 4;
        $shape = [$kind, $bw, $round, $bh];

        $r = self::radiusOf($a);

        // У прямоугольного тела радиус — это скругление, а не половина
        // стороны: раньше `radiusOf` вписывал такое тело в круг, и брусок
        // 200×50 становился кругом диаметром 50. Теперь форма переносится как
        // есть, а `r` перестаёт быть «размером» и становится скруглением.
        if ($round !== $r) {
            $r = round($round, 2);
        }
        $strand = WogFile::kids($def, 'strand')[0] ?? null;

        // Из чего шар сделан. Материал общий с геометрией — та же таблица, те
        // же трение и отскок, — и без него шар из теста и шар из камня
        // отличались бы только картинкой. Умолчания взяты те же, что стояли
        // зашитыми в движке, чтобы шар без материала вёл себя как прежде.
        $m = $mats[$a['material'] ?? ''] ?? ['smoothness' => 0.55, 'restitution' => 0.12];

        $mass = round(self::num($a, 'mass', 20) * self::MASS, 3);
        $tower = (isset($a['towermass']) ? (float) $a['towermass'] : self::num($a, 'mass', 20)) * self::MASS;
        // antigrav is a lift multiplier, and a signed weight expresses it whole:
        // inertia stays, only gravity flips.
        $built = round($tower * (1 - self::num($a, 'antigrav', 0)), 3);

        $picture = static function (string $id) use ($images, $root): array {
            $rel = $images[$id] ?? null;

            if ($rel === null) {
                return ['src' => '', 'aspect' => 1.0];
            }

            $size = WogFile::pngSize($root . '/' . $rel . '.png');

            return [
                'src' => $rel . '.png',
                // Width comes from the radius and height from the picture's
                // proportions. Without them a 200×50 body is drawn square,
                // which is a circle where a bar should be.
                'aspect' => $size !== null && $size['h'] > 0 ? round($size['w'] / $size['h'], 4) : 1.0,
            ];
        };

        return [
            'x' => 0, 'y' => 0,
            'r' => $r, 'builtR' => $r, 'sleepR' => $r,
            'mass' => $mass, 'builtMass' => $built, 'sleepMass' => $mass,
            'opacity' => 1,
            'anchorable' => !self::flag($a, 'grumpy'),
            'static' => self::flag($a, 'static'),
            'asleep' => false,
            // `strands` — сколько связей шар пробует построить; при нуле он в
            // постройку не встаёт вовсе, при одном встаёт на одну, при двух и
            // больше — только если нашлось две. Раньше верхний предел
            // поднимался до единицы, и Beauty, блоки и таблетки прицеплялись
            // одной связью.
            'minLinks' => min(2, (int) self::num($a, 'strands', 2)),
            'maxLinks' => max(0, (int) self::num($a, 'strands', 2)),
            'links' => [],
            // Reach comes from maxlen2, not maxlen1: the documentation is
            // explicit that maxlen1 is the limit between two balls already
            // attached, which is a different distance for a different moment.
            'range' => $strand !== null ? self::num($strand['attrs'], 'maxlen2', 165) : 165,
            'jump' => round(self::pair($a, 'jump')[1] * 950, 1),
            'speed' => round(self::num($a, 'walkspeed', 0) * 950, 1),
            'climbSpeed' => round(self::num($a, 'climbspeed', 2) * self::CLIMB, 1),
            'walkForce' => round(self::num($a, 'walkforce', 500) * self::FORCE),
            'dropMax' => 190,
            'linkSpring' => $strand !== null
                ? min(6000, max(100, round(self::num($strand['attrs'], 'springconstmax', 9) * self::SPRING)))
                : 1600,
            // Длина покоя — между двумя числами, а не одно. Раньше сюда шёл
            // `minlen` как единственная длина, и любая связь игрока стягивалась
            // ровно к нему: в сто пикселей у обычного шара, построй его хоть
            // на ста сорока. По описанию формата `minlen` — нижний предел
            // («короче — растягивается»), а стягивается связь к `shrinklen`,
            // у которого есть умолчание: 140. Ниже `minlen` она при этом не
            // уходит.
            'linkMin' => $strand !== null ? self::num($strand['attrs'], 'minlen', 0) : 0.0,
            'linkMax' => $strand !== null
                ? max(self::num($strand['attrs'], 'minlen', 0), self::num($strand['attrs'], 'shrinklen', 140))
                : 0.0,
            // Жёсткость связи на всю дальность — из `springconstmin`. В наборе
            // она отличается от ближней у одного вида из сорока девяти
            // (`SimCommon`), у остальных обе совпадают, и прямая вырождается
            // в постоянную.
            'linkSpringFar' => $strand !== null
                ? min(6000, max(100, round(self::num($strand['attrs'], 'springconstmin', self::num($strand['attrs'], 'springconstmax', 9)) * self::SPRING)))
                : 1600,
            // dampfac carries straight across, because both sides now mean the
            // same thing by it: a fraction of critical damping.
            //
            // It used to go into the report as "scales do not line up", and
            // that was the right call at the time — ours was a fraction of
            // relative velocity removed per substep, which cannot be compared
            // with a number reaching 1.9. But the format's own description
            // gives absolute thresholds — below 0.1 a strand wobbles for a long
            // time, above 0.7 the wobble dies quickly, most balls use 0.9 —
            // and absolute thresholds only hold for a dimensionless ratio. A
            // coefficient would have to be read together with the ball's mass
            // and spring constant, which run from 3 to 200 and from 2 to 9
            // across the set. So dampfac is a damping ratio, and the engine now
            // takes one too.
            'linkDamping' => $strand !== null ? min(4, self::num($strand['attrs'], 'dampfac', 0.9)) : 0.9,
            'linkBreak' => $strand !== null ? round(self::num($strand['attrs'], 'maxforce', 600) * self::BREAK) : 26000,
            'linkRope' => $strand !== null && ($strand['attrs']['type'] ?? '') === 'rope',
            'linkSrc' => $strand !== null && isset($strand['attrs']['image'])
                ? $picture(self::first($strand['attrs']['image']))['src']
                : '',
            // Липкость. В исходнике это три признака без величины: липнет
            // всегда, липнет только в конструкции, липнет только вне её. Силу
            // приходится назвать самим, и она выбрана не на глаз: тяготение в
            // наборе — 1800 у 54 уровней из 58, а держаться на потолке значит
            // перебить именно его. Взято вдвое больше, чтобы прилипшего не
            // сбивал первый же удар прилетевшего шара.
            'sticky' => self::stickyWhen($a) === '' ? 0 : 3600,
            'stickyWhen' => self::stickyWhen($a) === '' ? 'always' : self::stickyWhen($a),
            'smoothness' => $m['smoothness'],
            'bounce' => $m['restitution'],
            'linkWidth' => 0.6,
            'burnTime' => self::num($a, 'burntime', 0),
            // По описанию формата связь без `fireparticles` не горит вовсе —
            // это не украшение, а условие.
            'fireLinks' => $strand !== null && isset($strand['attrs']['fireparticles']),
            'fireDelay' => $strand !== null ? self::num($strand['attrs'], 'ignitedelay', 0) : 0.0,
            // Скорость пламени по связи. В исходнике счёт идёт на такт, а такт
            // там 1/50 — тем же множителем пересчитаны скорость лазания,
            // ходьбы и собственное вращение тел. В наборе стоит 2 и 3, то есть
            // 100 и 150 px/с: связь длиной 140 px прогорает за полторы секунды
            // и за секунду. Это похоже на то, что видно в прохождениях.
            //
            // Названо в описании просто «скоростью, с которой связь горит по
            // своей длине», без единиц. Если считать не такты, а доли длины в
            // секунду, выйдет вдвое-втрое быстрее — полсекунды на связь. Обе
            // величины одного порядка, и различить их можно только замером по
            // записи прохождения; взят такт, потому что в этом же формате все
            // остальные скорости заданы на такт.
            'fireSpeed' => $strand !== null ? round(self::num($strand['attrs'], 'burnspeed', 0) * 50, 1) : 0.0,
            'blastRadius' => self::num($a, 'detonateradius', 0),
            'blastForce' => self::num($a, 'detonateforce', 0),
            'fireColor' => '#ff9a3c',
            // След гибели — не поле шара, а картинка, которую он оставляет,
            // погибнув. Здесь она только находится; кладёт её рядом с шаром
            // тот, кто собирает ассет, и в самих полях шара её не остаётся.
            // Пятна следа: в наборе их бывает несколько, и игра берёт случайное.
            // Первое — то, что видно в редакторе; остальные достаются игре.
            'splatImages' => array_values(array_filter(array_map(
                static fn (string $one): string => $picture(trim($one))['src'],
                explode(',', WogFile::kids($def, 'splat')[0]['attrs']['image'] ?? ''),
            ))),
            // Имена вспышек: одна при лопании, другая при взрыве. Как и след,
            // в полях шара они не остаются — их забирает сборка ассета.
            'popFx' => trim($a['popparticles'] ?? ''),
            'blastFx' => trim($a['explosionparticles'] ?? ''),
            'wakeDist' => self::num($a, 'wakedist', 0),
            'color' => '#e2704a',
            'linkColor' => '#f0b48c',
            'parts' => self::parts($def, $r, $picture),
            'waves' => self::waves($def, $r),
            'sizes' => self::sizes($a),
            'sounds' => self::sounds($def, $sounds),
            // Крепкий: щадящая поверхность его не берёт. В исходнике признак
            // называется неуязвимостью, но губит крепкого по-прежнему всё, что
            // помечено смертельным без оговорок, — то есть это не полная
            // неуязвимость, а стойкость к одному разряду поверхностей.
            // Форма тела. Круг — это прямоугольник с нулевыми полуразмерами,
            // так что оба случая ложатся в одно и то же без особых веток.
            //
            // Скругление берётся как четверть меньшей стороны: у квадрата это
            // мягкий угол, у бруска 200×50 — почти капсула, и ни в одном
            // случае скругление не съедает форму целиком. Отдельного числа в
            // исходнике нет, а без скругления углы цеплялись бы за геометрию
            // намертво.
            'halfW' => $shape[0] === 'circle' ? 0.0 : round($shape[1] / 2 - $shape[2], 2),
            'halfH' => $shape[0] === 'circle' ? 0.0 : round($shape[3] / 2 - $shape[2], 2),
            'tough' => ($a['invulnerable'] ?? 'false') === 'true',
            // Лопающийся: по описанию формата лопаются те, у кого есть
            // начинка. Признак отдельный, а не выведенный из неё, чтобы автор
            // мог сделать и лопающийся пустой шар, и полный, который шестерни
            // не берут.
            'poppable' => trim($a['contains'] ?? '') !== '',
            // Ключа у шара нет. Однажды я проставил сюда название вида — чтобы
            // замок мог ждать «красную таблетку», — и это неверно дважды: вид
            // отвечает на «какой ты», а ключ на «кто ты», и один ключ на две с
            // половиной тысячи обычных шаров означал бы срабатывание неизвестно
            // от чего. К тому же спроса не было: замок, ради которого это
            // делалось, так и не поставлен.
            //
            // Понадобится — ключ проставит тот, кто ставит замок, и ровно тем
            // шарам, которых замок ждёт.
            'key' => '',
            // Свойство размещения, а не вида: шаг к цели засчитывает ОДНА
            // таблетка в `Deliverance`, а не всякая таблетка вообще.
            'popCounts' => false,
            // Можно ли цепляться к нему, пока он прилип. В наборе разведено
            // ровно по смыслу: у липких якорей разрешено — они и держатся за
            // стену затем, чтобы к ним цеплялись, — а у липких бомб и колючек
            // запрещено. Умолчание истинное, как и у обычной сцепки: шар, про
            // который не сказано ничего, ведёт себя как раньше.
            'anchorableStuck' => ($a['stuckattachment'] ?? 'true') !== 'false',
            'suckable' => self::flag($a, 'suckable', true),
            // Рука игрока. Вынуть из постройки можно только вид, у которого
            // есть `detachstrand`: описание формата называет этот тег
            // обязательным для вынимания, и `maxlen` в нём — то, насколько
            // шар надо оттянуть, прежде чем он выйдет. У обычного шара
            // `detachable="false"`, то есть построенное стоит.
            'draggable' => self::flag($a, 'draggable', true),
            // Вес в руке. У глыбы он вшестеро меньше её собственного, у окна
            // втрое: рука тянет шар пружиной, и без облегчения тяжёлое
            // волочилось бы за курсором, не поспевая.
            'dragMass' => round(self::num($a, 'dragmass', 0) * self::MASS, 3),
            'detachable' => self::flag($a, 'detachable', true) && WogFile::kids($def, 'detachstrand') !== [],
            'detachDist' => self::num((WogFile::kids($def, 'detachstrand')[0] ?? ['attrs' => []])['attrs'], 'maxlen', 0),
            'climber' => self::flag($a, 'climber', true),
            // Моргание задано одним цветом — каким видно закрытый глаз. Стоит
            // у девятнадцати видов, то есть почти у всех, кто с глазами.
            'blinkColor' => isset($a['blinkcolor']) ? self::colour($a['blinkcolor']) : '',
            // Метка под рукой: своя для «возьму» и своя для «выну». Крутится
            // она оборотами в секунду, и знак говорит, в какую сторону.
            // Предел скорости стоит у балуна, рыбы, кости, Pokey и EvilEye —
            // у тех, кого носят и роняют. Самоцепляние — только у Pilot,
            // которого запускают, и он пристаёт к тому, во что попал.
            'attachSpeed' => self::num($a, 'maxattachspeed', 0),
            'autoAttach' => self::flag($a, 'autoattach'),
            'behindLinks' => self::flag($a, 'isbehindstrands'),
            'markerDrag' => $picture(self::first(WogFile::kids($def, 'marker')[0]['attrs']['drag'] ?? ''))['src'],
            'markerDetach' => $picture(self::first(WogFile::kids($def, 'marker')[0]['attrs']['detach'] ?? ''))['src'],
            'markerSpin' => self::num(WogFile::kids($def, 'marker')[0]['attrs'] ?? [], 'rotspeed', 0),
            'eyesFollowPointer' => self::flag($a, 'alwayslookatmouse'),
            // Спящий держится на месте весь: замер там, где его поставили.
            //
            // Так помечены пять видов, и трое из них — балун, рыба, кость —
            // сами по себе не лежат: у первых двух подъёмная сила. По замеру на
            // наборе без этого признака уезжали с места, не просыпаясь, 113
            // спящих шаров из 142, и самый резвый уплывал на тысячу пикселей —
            // гроздь спящих балунов в `WeatherVane` тихо поднималась к потолку
            // ещё до первого хода игрока.
            // Спящий держится на месте ВСЕГДА, а не только с признаком.
            //
            // Признак `staticwhensleeping` стоит у пяти видов, и сперва я брал
            // держание только от него. Опровергает это сам набор: в
            // `BulletinBoardSystem` вся левая постройка — двадцать два спящих
            // `Pixel`, у которого признака нет, и висит она в боковом
            // тяготении без единой опоры. Падай спящие там — уровень был бы
            // сломан ещё до первого хода игрока.
            //
            // Значит спящий в исходнике не движется сам по себе, а признак
            // говорит о чём-то более узком. Что именно он добавляет, из данных
            // не видно, и пока он не различается: держатся все спящие.
            'sleepHold' => 'place',
            'wakeJump' => self::flag($a, 'jumponwakeup'),
            // Разброс скорости — доля, на которую темп шара отличается от
            // объявленного. В исходнике это `speedvariance` с тем же смыслом и
            // в тех же долях: 0 — все идут одинаково, 1 — кто-то стоит, а
            // кто-то вдвое быстрее. Пересчитывать нечего.
            'speedSpread' => min(1, max(0, self::num($a, 'speedvariance', 0))),
            // Два признака про то, как шары толкаются друг с другом, и они
            // разные. `collidewithattached` — толкаюсь с теми, кто в
            // конструкции, чем бы ни был занят сам: так сделаны глыбы и
            // Beauty. `collideattached` — толкаюсь с ними, только пока сам
            // стою в конструкции: так сделаны балуны и Pilot, которые
            // распирают соседей, но сквозь чужую постройку пролетают.
            // Блоки и окна: лежат стопкой, замирают, берутся за точку щелчка.
            // Без этих трёх глыбы в `RoadBlocks` и `GracefulFailure`
            // проваливались бы друг сквозь друга и медленно расползались.
            //
            // У `stacking` в описании есть и вторая половина: такой шар,
            // погибая, разлетается осколками, как геометрия. Она не перенесена
            // — это про смерть и вид, а не про то, как он лежит.
            // Запуск из руки: длина стрелки и толчок при полной оттяжке.
            ...self::fling($a),
            'stacks' => self::flag($a, 'stacking'),
            'freezeWhenStill' => self::flag($a, 'autodisable'),
            'hingeDrag' => self::flag($a, 'hingedrag'),
            'hitsBuilt' => self::flag($a, 'collidewithattached'),
            'builtHitsBuilt' => self::flag($a, 'collideattached'),
            'angle' => 0.0,
        ];
    }

    /**
     * The collision circle.
     *
     * 44 of the 54 types in the set say `circle`, so for most of them this is
     * exactly what the original has. Ten say `rectangle`, seven of those appear
     * in levels — 182 balls out of 2720 — and six of the seven are square.
     *
     * The largest side was the wrong one to take: a 200×50 bar became a circle
     * 200 across, four times too thick, so it would not fit where it used to
     * and shoved its neighbours away at that radius. The smallest side is wrong
     * along one axis instead of wrong in every direction.
     *
     * @param array<string, string> $a
     */
    private static function radiusOf(array $a): float
    {
        $shape = explode(',', $a['shape'] ?? 'circle,30');
        $first = (float) ($shape[1] ?? 30);

        if (($shape[0] ?? 'circle') === 'circle') {
            return $first / 2;
        }

        return min($first, (float) ($shape[2] ?? $first)) / 2;
    }

    /**
     * Layered sprites.
     *
     * A picture attribute can list several, and the original picks one per
     * ball, so a type's bodies are not all identical. Here that becomes several
     * parts sharing a pick group: the cell stays one picture, the author sees
     * every variant as its own row, and can edit each.
     *
     * @param array<string, mixed>                            $def
     * @param callable(string): array{src: string, aspect: float} $picture
     *
     * @return list<array<string, mixed>>
     */
    private static function parts(array $def, float $r, callable $picture): array
    {
        $out = [];
        $groups = [];

        foreach (WogFile::kids($def, 'part') as $p) {
            $a = $p['attrs'];
            $names = self::list($a['image'] ?? '');

            if ($names === []) {
                continue;
            }

            $name = $a['name'] ?? 'part';
            $group = 0;

            if (count($names) > 1) {
                $groups[$name] ??= count($groups) + 1;
                $group = $groups[$name];
            }

            $stretch = array_map('floatval', self::list($a['stretch'] ?? ''));
            $xr = array_map('floatval', self::list($a['xrange'] ?? ''));
            $yr = array_map('floatval', self::list($a['yrange'] ?? ''));
            $x = self::middle($a['x'] ?? '0');
            $y = self::middle($a['y'] ?? '0');

            foreach ($names as $id) {
                $pic = $picture($id);
                $out[] = [
                    'name' => $name,
                    'src' => $pic['src'],
                    'aspect' => $pic['aspect'],
                    'poses' => self::poses($a['state'] ?? ''),
                    // Offsets are in radii, not pixels: a ball changes size
                    // between its profiles and its parts have to travel with it.
                    'dx' => round($x / $r, 4),
                    'dy' => round(-$y / $r, 4),
                    'scale' => self::num($a, 'scale', 1),
                    'layer' => (int) self::num($a, 'layer', 0),
                    'rotate' => self::flag($a, 'rotate'),
                    'pick' => $group,
                    'pupil' => isset($a['pupil']) ? $picture(self::first($a['pupil']))['src'] : '',
                    // pupilinset is the distance from the eye's edge, not the
                    // pupil's size: too small and the pupil shows outside the
                    // eye, which is the original's behaviour and not a bug.
                    'pupilInset' => round(self::num($a, 'pupilinset', 0) / $r, 4),
                    'pupilSize' => 0.3,
                    // stretch = {speed},{along},{across}, and it is the ball's
                    // MOVEMENT that stretches a part, not the pull on a strand.
                    'speedRef' => $stretch[0] ?? 0.0,
                    'stretchAlong' => $stretch[1] ?? 1.0,
                    'stretchAcross' => $stretch[2] ?? 1.0,
                    // xrange/yrange is the span a part sways within as the ball
                    // moves. With none given the part does not move at all —
                    // that is what the documentation says, and assuming the
                    // opposite would have every googly eye backwards.
                    'dxMin' => count($xr) > 1 ? round(($xr[0] - $x) / $r, 4) : 0,
                    'dxMax' => count($xr) > 1 ? round(($xr[1] - $x) / $r, 4) : 0,
                    'dyMin' => count($yr) > 1 ? round(-($yr[1] - $y) / $r, 4) : 0,
                    'dyMax' => count($yr) > 1 ? round(-($yr[0] - $y) / $r, 4) : 0,
                ];
            }
        }

        return $out;
    }

    /**
     * Sine animation, one row per wave.
     *
     * Variance wraps a group of sines rather than sitting on each, and the
     * grouping is load-bearing: a walk moves the body and both eyes, and if
     * each drew its own random phase the walk would fall apart. So the wrapper
     * gets a number that all its sines share.
     *
     * @param array<string, mixed> $def
     *
     * @return list<array<string, mixed>>
     */
    private static function waves(array $def, float $r): array
    {
        $out = [];
        $group = 0;

        foreach (WogFile::kids($def, 'sinvariance') as $v) {
            $group++;
            $va = $v['attrs'];

            foreach (WogFile::kids($v, 'sinanim') as $w) {
                $wa = $w['attrs'];
                $scale = ($wa['type'] ?? '') === 'scale';
                $axis = ($wa['axis'] ?? 'x') === 'y' ? 'y' : 'x';
                $prop = $scale ? ($axis === 'y' ? 'sy' : 'sx') : ($axis === 'y' ? 'dy' : 'dx');

                // Amplitude is a factor for scale and PIXELS for translate, so
                // translation is divided by the radius — ours is in radii — and
                // flipped along with the y axis.
                $amp = $scale
                    ? self::num($wa, 'amp', 0)
                    : round(self::num($wa, 'amp', 0) / $r * ($axis === 'y' ? -1 : 1), 4);

                foreach (self::list($wa['part'] ?? '') as $part) {
                    foreach (self::poses($wa['state'] ?? '') as $pose) {
                        $out[] = [
                            'part' => $part,
                            'pose' => $pose,
                            'prop' => $prop,
                            'freq' => self::num($wa, 'freq', 0),
                            'amp' => $amp,
                            'shift' => self::num($wa, 'shift', 0),
                            'group' => $group,
                            'vFreq' => self::num($va, 'freq', 0),
                            'vAmp' => $scale ? self::num($va, 'amp', 0) : round(self::num($va, 'amp', 0) / $r, 4),
                            'vShift' => self::num($va, 'shift', 0),
                        ];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $a
     *
     * @return list<array{pose: string, scale: float}>
     */
    private static function sizes(array $a): array
    {
        $out = [];
        $pairs = self::list($a['statescales'] ?? '');

        for ($i = 0; $i + 1 < count($pairs); $i += 2) {
            $pose = self::POSES[$pairs[$i]] ?? null;

            if ($pose !== null) {
                $out[] = ['pose' => $pose, 'scale' => (float) $pairs[$i + 1]];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>  $def
     * @param array<string, string> $sounds
     *
     * @return list<array{event: string, src: string, volume: float}>
     */
    private static function sounds(array $def, array $sounds): array
    {
        $out = [];

        foreach (WogFile::kids($def, 'sound') as $s) {
            $names = self::list($s['attrs']['id'] ?? '');
            $event = $s['attrs']['event'] ?? '';
            $rel = $names === [] ? null : ($sounds[$names[0]] ?? null);

            if ($event === '' || $rel === null) {
                continue;
            }

            $out[] = ['event' => $event, 'src' => $rel . '.ogg', 'volume' => 1.0];
        }

        return $out;
    }

    /** @return list<string> */
    private static function poses(string $list): array
    {
        $out = [];

        foreach (self::list($list) as $name) {
            if (isset(self::POSES[$name])) {
                $out[] = self::POSES[$name];
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function list(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $s): bool => $s !== ''));
    }

    private static function first(string $raw): string
    {
        return self::list($raw)[0] ?? '';
    }

    /** A value that may be given as a span; the middle of it if so. */
    private static function middle(string $raw): float
    {
        $parts = array_map('floatval', self::list($raw));

        return match (count($parts)) {
            0 => 0.0,
            1 => $parts[0],
            default => ($parts[0] + $parts[1]) / 2,
        };
    }

    /** @param array<string, string> $a */
    /**
     * Который из трёх случаев липкости, если он вообще есть.
     *
     * Порядок проверки не важен: в наборе ни у одного вида не стоит больше
     * одного из трёх признаков сразу.
     *
     * @param array<string, string> $a
     */
    private static function stickyWhen(array $a): string
    {
        if (($a['sticky'] ?? 'false') === 'true') {
            return 'always';
        }

        if (($a['stickyattached'] ?? 'false') === 'true') {
            return 'built';
        }

        if (($a['stickyunattached'] ?? 'false') === 'true') {
            return 'free';
        }

        return '';
    }

    /**
     * Начинка из `contains`: пары «сколько, кого», через запятую.
     *
     * @param array<string, string> $a
     *
     * @return list<array<string, mixed>>
     */
    public static function births(array $a): array
    {
        $raw = trim($a['contains'] ?? '');

        if ($raw === '') {
            return [];
        }

        $bits = array_map('trim', explode(',', $raw));
        $out = [];

        for ($i = 0; $i + 1 < count($bits); $i += 2) {
            $count = (int) $bits[$i];
            $kind = $bits[$i + 1];

            if ($count <= 0 || $kind === '') {
                continue;
            }

            $out[] = ['count' => $count, 'asset' => 'wog-ball-' . mb_strtolower($kind)];
        }

        return $out;
    }

    /** @param array<string, string> $a */
    private static function num(array $a, string $key, float $default = 0): float
    {
        return isset($a[$key]) && $a[$key] !== '' && is_numeric($a[$key]) ? (float) $a[$key] : $default;
    }

    /** @param array<string, string> $a */
    /**
     * @param array<string, string> $a
     *
     * @return array{flingRange: float, flingPush: float}
     */
    private static function fling(array $a): array
    {
        $parts = array_map('trim', explode(',', $a['fling'] ?? ''));

        if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            return ['flingRange' => 0.0, 'flingPush' => 0.0];
        }

        $arrow = (float) $parts[0];
        $mult = (float) $parts[1];

        return [
            'flingRange' => round($arrow, 1),
            'flingPush' => round($arrow * $mult * self::FLING, 1),
        ];
    }

    /**
     * Цвет из чужой записи: три числа от нуля до 255 через запятую.
     *
     * Пустая строка, если записано не то: пропущенный цвет лучше выдуманного —
     * шар просто не будет моргать, а не станет моргать чем попало.
     */
    private static function colour(string $said): string
    {
        $parts = array_map('trim', explode(',', $said));

        if (count($parts) !== 3) {
            return '';
        }

        $hex = '#';

        foreach ($parts as $one) {
            if (!is_numeric($one)) {
                return '';
            }

            $hex .= sprintf('%02x', max(0, min(255, (int) $one)));
        }

        return $hex;
    }

    /** @param array<string, string> $a */
    private static function flag(array $a, string $key, bool $default = false): bool
    {
        return isset($a[$key]) ? $a[$key] === 'true' : $default;
    }

    /**
     * @param array<string, string> $a
     *
     * @return array{0: float, 1: float}
     */
    private static function pair(array $a, string $key): array
    {
        $parts = array_map('floatval', self::list($a[$key] ?? ''));

        return [$parts[0] ?? 0.0, $parts[1] ?? 0.0];
    }
}
