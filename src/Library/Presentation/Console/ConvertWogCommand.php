<?php

declare(strict_types=1);

namespace Wob\Library\Presentation\Console;

use Illuminate\Console\Command;
use Wob\Library\Infrastructure\Foreign\WogBallKind;
use Wob\Library\Infrastructure\Foreign\WogFile;
use Wob\Library\Infrastructure\Foreign\WogGeometry;
use Wob\Library\Infrastructure\Foreign\WogLevel;
use Wob\Library\Infrastructure\Foreign\WogMovie;
use Wob\Library\Infrastructure\Foreign\WogTables;

/**
 * Turn a folder of the original game's files into a bundle of ours.
 *
 * This is the first of the two halves of importing, and it puts a file between
 * them on purpose. When an import used to go wrong there was nothing to look
 * at, only a report saying how many things had not made it; now there is a
 * bundle that can be opened, diffed, and handed to the loader separately.
 *
 * It does not touch the database and does not need one. It also knows nothing
 * about our entities beyond the shape of their data: every field it writes is
 * named in `WogLevel` and `WogBallKind` with a value chosen on purpose, because
 * a saved level has to be complete — a field arriving from an engine default
 * would change the day somebody edited the engine.
 */
final class ConvertWogCommand extends Command
{
    protected $signature = 'wob:convert
        {source : folder of the original game files (its res directory)}
        {--out=bundle.json : where to write the bundle}
        {--media= : also write the list of files to upload}
        {--fields= : also write which fields it fills, for the client to check against}
        {--films= : where to put the cutscenes it builds (next to the bundle by default)}
        {--quiet-report : do not print what did not come across}';

    protected $description = 'Convert a folder of original game files into a wob bundle';

    /** @var array<string, array<string, int>> what did not come across, by level */
    private array $missed = [];

    /** @var list<string> */
    private array $skipped = [];

    /**
     * Заставки набора, собранные в наши файлы.
     *
     * Кладутся рядом с пакетом, а не под чужой папкой: это наш файл, а не их.
     * Собираются все, какие есть, — какая из них кому достанется, решают уже
     * острова.
     *
     * @param array<string, string> $images
     * @param array<string, string> $strings
     *
     * @return array<string, string>
     */
    private function makeFilms(string $root, array $images, array $strings): array
    {
        $into = rtrim((string) ($this->option('films') ?: dirname((string) $this->option('out')) . '/films'), '/');
        $out = [];

        foreach (glob($root . '/movie/*/*.movie.binltl') ?: [] as $file) {
            $name = basename($file, '.movie.binltl');
            $film = WogMovie::toSvg($file, $root, $images, $strings);

            if ($film === null) {
                $this->missed['(набор)']['заставка не читается'] ??= 0;
                $this->missed['(набор)']['заставка не читается']++;

                continue;
            }

            if (!is_dir($into) && !mkdir($into, 0o777, true) && !is_dir($into)) {
                return [];
            }

            $path = $into . '/' . $name . '.svg';
            file_put_contents($path, $film);
            $out[$name] = $path;
        }

        return $out;
    }

    /** Средний из трёх: чем закрыть, ЧТО ПОКАЗАТЬ, чем открыть. */
    private static function filmOf(string $cutscene): string
    {
        $parts = array_map('trim', explode(',', $cutscene));

        return count($parts) === 3 ? $parts[1] : '';
    }

    /**
     * Острова набора: название и уровни в порядке зависимостей.
     *
     * @var list<array{title: string, levels: list<array{
     *     id: string, depends: string, name: string,
     *     text: string, cutscene: string, oncomplete: string,
     * }>}>
     */
    private array $islands = [];

    /**
     * Отличие сверх прохождения, по уровням.
     *
     * Живёт оно не в уровне, а в острове — там же, где порядок уровней, — и
     * поэтому читается вместе со списком, а не при разборе уровня.
     *
     * @var array<string, array{by: string, value: float, required: bool}|null>
     */
    private array $mark = [];

    /**
     * Таблица текстов набора. Читается раньше островов, потому что название
     * уровня лежит в ней, а в острове стоит только ключ.
     *
     * @var array<string, string>
     */
    private array $strings = [];

    /**
     * Заставки, собранные из чужих фильмов: имя фильма → путь к нашему файлу.
     *
     * @var array<string, string>
     */
    private array $films = [];

    /** Корень чужого набора. Нужен разделам, которые идут после чтения файлов. */
    private string $root = '';

    /**
     * Картинки следов гибели и частиц у шаров. Собираются при сборке ассетов, а список файлов к
     * заливке пишется раньше — поэтому лежат здесь, а не в местной переменной.
     *
     * @var array<string, string>
     */
    private array $marks = [];

    /**
     * Таблицы набора, нужные при сборке ассетов: лопающийся шар и бомба носят
     * свою вспышку с собой, а собирается ассет позже, чем читаются файлы.
     *
     * @var array<string, string>
     */
    private array $images = [];

    /** @var array<string, array<string, mixed>> */
    private array $fx = [];

    public function handle(): int
    {
        $root = rtrim((string) $this->argument('source'), '/');

        if (!is_dir($root . '/levels')) {
            $this->error("No levels in {$root} — point this at the game's res folder");

            return self::FAILURE;
        }

        $res = WogTables::resources($root);
        $mats = WogTables::materials($root);
        $fx = WogTables::effects($root);
        $strings = $this->strings = WogTables::strings($root);
        $this->root = $root;
        $this->images = $res['images'];
        $this->fx = WogTables::effects($root);
        $this->films = $this->makeFilms($root, $res['images'], $strings);
        $balls = $this->ballDefinitions($root);

        $levels = [];
        $kinds = [];
        $fillings = [];
        $media = [];

        foreach ($this->levelNames($root) as $name) {
            $scene = $this->read("{$root}/levels/{$name}/{$name}.scene.bin");
            $level = $this->read("{$root}/levels/{$name}/{$name}.level.bin");

            if ($scene === null || $level === null) {
                $this->missed['(набор)']['уровень без сцены или описания'] ??= 0;
                $this->missed['(набор)']['уровень без сцены или описания']++;

                continue;
            }

            $made = (new WogLevel(
                $name,
                $scene,
                $level,
                $res,
                $root,
                $mats,
                $fx,
                $strings,
                $balls,
                function (string $what, int $count = 1) use ($name): void {
                    $this->missed[$name][$what] ??= 0;
                    $this->missed[$name][$what] += $count;
                },
            ))->convert();

            $built = $made['level'];
            // Бонусным, а не обязательным: в исходнике отличие никогда не
            // мешает пройти уровень, оно только даёт флажок.
            $built['extra'] = $this->mark[$name] ?? null;
            $levels[] = $built;
            $media += $made['media'];
            $kinds += $made['kinds'];
            $fillings += $made['fillings'];
        }

        $bundle = $this->bundle($levels, $kinds, $fillings);

        $out = (string) $this->option('out');
        file_put_contents($out, json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->line(sprintf(
            'уровней собрано: %d, ассетов шаров: %d, файлов к заливке: %d',
            count($levels),
            count($kinds),
            count($media),
        ));
        $this->line("записано: {$out}");

        $mediaOut = (string) $this->option('media');

        if ($mediaOut !== '') {
            // Заставки — тоже файлы к заливке, хоть и собранные нами.
            foreach ($this->films as $path) {
                $media[$path] = $path;
            }

            $media += $this->marks;

            file_put_contents($mediaOut, json_encode($media, JSON_UNESCAPED_SLASHES));
            $this->line("список файлов: {$mediaOut}");
        }

        $fieldsOut = (string) $this->option('fields');

        if ($fieldsOut !== '') {
            $fields = $this->fields($bundle);
            file_put_contents($fieldsOut, json_encode($fields, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
            $this->line(sprintf('поля заготовок: %s (%d видов сущностей)', $fieldsOut, count($fields['entities'])));
        }

        if ($this->skipped !== []) {
            $this->line(sprintf(
                'не уровни, пропущены (%d): %s',
                count($this->skipped),
                implode(', ', $this->skipped),
            ));
        }

        if (!$this->option('quiet-report')) {
            $this->report();
        }

        return self::SUCCESS;
    }

    /**
     * Which fields this converter fills, and with what kind of value.
     *
     * Read off what was actually produced rather than declared in a constant
     * beside the code, because a declaration is a second copy that drifts. The
     * whole reference set goes through here, so the union over every entity of
     * a kind is the honest answer to "what does the converter write".
     *
     * It exists because the converter names every field itself: a saved level
     * must be complete, so nothing may arrive from an engine default, and the
     * converter therefore cannot ask the engine what the fields are. The price
     * of that decoupling is that the two can drift in silence — an entity grows
     * a field, the converter never hears about it, and levels arrive with a
     * hole. The client checks this file against its registry.
     *
     * A field that only some entities of a kind carry is listed too, and marked
     * as such: that is either a bug in here or a field the client makes
     * optional, and both are worth seeing rather than averaging away.
     *
     * @param array<string, mixed> $bundle
     *
     * @return array<string, mixed>
     */
    private function fields(array $bundle): array
    {
        /** @var array<string, array<string, string>> $kinds */
        $kinds = [];
        /** @var array<string, array<string, int>> $seen */
        $seen = [];
        /** @var array<string, int> $total */
        $total = [];

        $note = function (array $entity) use (&$kinds, &$seen, &$total): void {
            $type = (string) ($entity['type'] ?? '');
            $data = $entity['data'] ?? [];

            if ($type === '' || !is_array($data)) {
                return;
            }

            // Размещение, стоящее на ассете, хранит одни отличия и полным быть
            // не должно — в этом весь смысл ссылки. Считать его наравне с
            // остальными значило бы объявить необязательным почти всё, что есть
            // у шара, потому что в уровне у него лежат три поля из сорока.
            if (isset($entity['asset'])) {
                return;
            }

            $total[$type] = ($total[$type] ?? 0) + 1;

            foreach ($data as $key => $value) {
                $kinds[$type][$key] = self::kindOf($value);
                $seen[$type][$key] = ($seen[$type][$key] ?? 0) + 1;
            }
        };

        foreach ($bundle['levels'] as $level) {
            foreach ($level['entities'] as $entity) {
                $note($entity);
            }
        }

        // Ассет — та же сущность, только вынесенная: поля у неё те же, и
        // пропустить их значило бы не проверять как раз шар, у которого их
        // больше всех.
        foreach ($bundle['assets'] as $asset) {
            foreach ($asset['entities'] as $entity) {
                $note($entity);
            }
        }

        $entities = [];

        foreach ($kinds as $type => $byKey) {
            ksort($byKey);
            $sometimes = [];

            foreach (array_keys($byKey) as $key) {
                if (($seen[$type][$key] ?? 0) < ($total[$type] ?? 0)) {
                    $sometimes[] = $key;
                }
            }

            $entities[$type] = ['fields' => $byKey, 'sometimes' => $sometimes];
        }

        ksort($entities);

        return [
            // Шкала слоёв. Конвертер раскладывает картинки по глубине своими
            // числами; сдвинься она в движке — задник окажется перед
            // конструкцией, и ничто об этом не скажет: уровень соберётся,
            // просто нарисуется наизнанку.
            'layers' => ['background' => -60, 'midground' => 0, 'overlay' => 70],
            'entities' => $entities,
        ];
    }

    private static function kindOf(mixed $value): string
    {
        return match (true) {
            is_array($value) => array_is_list($value) ? 'array' : 'object',
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            is_string($value) => 'string',
            default => 'null',
        };
    }

    /**
     * What did not come across, and how widely.
     *
     * Printed by default rather than on request. A converter that reports only
     * success teaches you to trust it, and this one leaves things behind on
     * every run — the honest number is part of the result, not a debugging aid.
     */
    private function report(): void
    {
        $total = [];
        $levels = [];

        foreach ($this->missed as $byLevel) {
            foreach ($byLevel as $what => $count) {
                $total[$what] = ($total[$what] ?? 0) + $count;
                $levels[$what] = ($levels[$what] ?? 0) + 1;
            }
        }

        if ($total === []) {
            $this->info('перенеслось всё');

            return;
        }

        arsort($levels);
        $this->newLine();
        $this->line('НЕ ПЕРЕНЕСЕНО (сколько уровней задето, сколько штук всего):');

        foreach ($levels as $what => $count) {
            $this->line(sprintf('  %3d ур.  %6d шт  %s', $count, $total[$what], $what));
        }
    }

    /**
     * @param list<array<string, mixed>>          $levels
     * @param array<string, array<string, mixed>> $kinds
     *
     * @return array<string, mixed>
     */
    /**
     * @param list<array<string, mixed>>                                  $levels
     * @param array<string, array<string, mixed>>                         $kinds
     * @param array<string, list<array{count: int, asset: string}>>       $fillings
     *
     * @return array<string, mixed>
     */
    private function bundle(array $levels, array $kinds, array $fillings): array
    {
        // Главы — острова. Раньше все уровни сваливались в одну «Всё подряд»
        // цепочкой по алфавиту: играть можно, но это не та игра. В исходнике
        // пять островов, у каждого своё название и свой порядок, а порядок
        // задан не списком, а зависимостями — «этот откроется, когда пройден
        // тот». Зависимость и есть тропа между точками.
        //
        // Раскладки точек на карте в данных нет: там она нарисована руками в
        // отдельной сцене, которую мы уровнем не считаем. Поэтому точки
        // раскладываются по глубине зависимости — цепочка вправо, ветка вниз, —
        // и это честная карта, просто не та же самая.
        $chapters = [];
        $byId = [];

        foreach ($levels as $l) {
            $byId[$l['id']] = true;
        }

        // Набор без островов. `levelNames` в этом случае берёт всё, что лежит,
        // но уровень, не стоящий ни в одной главе, заливка не сохраняет вовсе:
        // глава — единственное, что держит уровень в истории. Отдать пакет с
        // уровнями и без глав значило бы молча выбросить всё на заливке.
        // Поэтому одна глава, цепочкой в том порядке, в каком уровни собраны.
        $islands = $this->islands;

        if ($islands === [] && $levels !== []) {
            $chain = [];
            $prev = '';

            foreach ($levels as $l) {
                $name = substr((string) $l['id'], strlen('wog-'));
                $chain[] = [
                    'id' => $name, 'depends' => $prev,
                    'name' => '', 'text' => '', 'cutscene' => '', 'oncomplete' => '',
                ];
                $prev = $name;
            }

            $islands = [['title' => 'Всё подряд', 'levels' => $chain]];
        }

        foreach ($islands as $n => $isle) {
            $mine = array_values(array_filter(
                $isle['levels'],
                static fn (array $one): bool => isset($byId['wog-' . mb_strtolower($one['id'])]),
            ));

            if ($mine === []) {
                continue;
            }

            $depth = [];
            $nodes = [];
            $lane = [];

            foreach ($mine as $one) {
                $from = $one['depends'];
                $step = $from !== '' && isset($depth[$from]) ? $depth[$from] + 1 : 0;
                $depth[$one['id']] = $step;
                $row = $lane[$step] = ($lane[$step] ?? -1) + 1;

                $nodes[$one['id']] = [
                    'id' => 'nd-wog-' . mb_strtolower($one['id']),
                    'levelId' => 'wog-' . mb_strtolower($one['id']),
                    'x' => 6 + $step * 9,
                    'y' => 10 + $row * 14,
                    'next' => [],
                    // Название точки на карте. В исходнике оно лежит не в
                    // острове, а в таблице текстов — в острове только ключ, —
                    // и без него игрок видел на карте `AB3` и `MOM` вместо
                    // «Алиса, Боб и третий лишний».
                    //
                    // Именно точке, а не уровню: по решению автора один и тот
                    // же уровень, встреченный дважды, — два разных места в
                    // истории, и название у каждого своё. Рабочее имя уровня
                    // при этом остаётся тем, каким названа его папка.
                    'name' => str_replace("\n", ' ', $this->strings[$one['name']] ?? ''),
                    // Вторая строка под названием: «проще вареной тянучки».
                    // В исходнике она такой же ключ таблицы, как и название.
                    'note' => str_replace("\n", ' ', $this->strings[$one['text']] ?? ''),
                    // Ролик после победы. В исходнике он записан тройкой
                    // «чем закрыть, что показать, чем открыть»; показывается
                    // средний, а створки — устройство чужого перехода.
                    'outro' => $this->films[self::filmOf($one['cutscene'])] ?? '',
                ];

                // Что у точки есть в исходнике, а у нас положить некуда.
                //
                // Подпись под названием — строка вроде «проще вареной тянучки».
                // Место для неё на карте не предусмотрено: у точки есть имя и
                // картинка, а второй строки нет.
                //
                // Ролик после уровня у точки как раз есть, но у нас это видео,
                // а в исходнике — кукольный мультфильм: список картинок со
                // своими дорожками движения в двоичном файле. Превратить одно в
                // другое значит его нарисовать и снять, а не перенести.
                //
                // Действие по прохождению открывает башню Корпорации, свисток
                // или продолжение главы. Всё трое — устройство чужого меню, а
                // меню у нас своё: тропы между точками уже говорят, что за чем
                // открывается.
                if ($one['cutscene'] !== '' && ($this->films[self::filmOf($one['cutscene'])] ?? '') === '') {
                    $this->missed[$one['id']]['ролик после уровня — его не удалось собрать'] = 1;
                }

                // Реплики и разовые звуки заставки. Файлы лежат рядом с ней, а
                // вот когда каждый звучит — не написано нигде: это решала сама
                // игра. Ставить наугад значило бы сочинять, поэтому сказано
                // вслух.
                $voices = WogMovie::soundsBesidesMusic(
                    $this->root . '/movie/' . self::filmOf($one['cutscene']),
                    self::filmOf($one['cutscene']),
                );

                if ($voices > 0) {
                    $this->missed[$one['id']]['звук в заставке — когда он звучит, в наборе не сказано'] = $voices;
                }

                if ($one['oncomplete'] !== '') {
                    $this->missed[$one['id']]['действие по прохождению — оно про чужое меню'] = 1;
                }
            }

            // Тропы: от того, от кого зависят, к тому, кто зависит.
            foreach ($mine as $one) {
                $from = $one['depends'];

                if ($from !== '' && isset($nodes[$from])) {
                    $nodes[$from]['next'][] = $nodes[$one['id']]['id'];
                }
            }

            $chapters[] = [
                'id' => 'wog-ch' . ($n + 1), 'storyId' => 'wog',
                'title' => $isle['title'],
                'image' => '', 'map' => '', 'canvas' => ['w' => 1600, 'h' => 900],
                'hot' => [], 'nodes' => array_values($nodes),
            ];
        }

        $assets = [];

        foreach ($kinds as $type => $data) {
            $id = 'wog-ball-' . mb_strtolower($type);
            // Ассет — группа: сам шар, а если у него есть начинка — ещё
            // рождение, прицепленное к шару, и содержимое, прицепленное к
            // рождению. Содержимое — ссылки на другие такие же ассеты, поэтому
            // восемьдесят штук внутри `UndeletePill` хранятся как восемьдесят
            // ссылок, а не как восемьдесят копий дерева.
            //
            // По ободу, а не в одну точку: полтора десятка тел, вложенных друг
            // в друга, разожмутся взрывом. Круг считается числами, без всякой
            // случайности, иначе повтор прогона перестал бы сходиться.
            $inside = [];
            $rows = $fillings[$type] ?? [];
            $total = 0;

            foreach ($rows as $row) {
                $total += (int) $row['count'];
            }

            // След гибели. Он ложится тем же способом, что и начинка: рождением,
            // которое выпускает отложенное, когда шар погиб, — и только когда
            // погиб. Ни трубе, ни улетевшему за край след не полагается, и
            // рождение это уже знает, так что движку про следы знать нечего.
            //
            // В наборе у следа бывает несколько картинок, из которых игра берёт
            // случайную; мы берём первую — разнообразия меньше, но выдумывать
            // выбор не из чего.
            $marks = (array) ($data['splatImages'] ?? []);
            unset($data['splatImages']);
            $mark = (string) ($marks[0] ?? '');

            $hasFx = ($this->fx[(string) ($data['popFx'] ?? '')] ?? null) !== null
                || ($this->fx[(string) ($data['blastFx'] ?? '')] ?? null) !== null;

            if ($total > 0 || $mark !== '' || $hasFx) {
                $inside[] = [
                    'id' => $id . '-birth', 'type' => 'birth', 'parent' => $id . '-e',
                    'data' => ['x' => 0.0, 'y' => 0.0],
                ];
                $k = 0;

                foreach ($rows as $row) {
                    for ($i = 0; $i < (int) $row['count']; $i++, $k++) {
                        $angle = ($k / $total) * M_PI * 2;
                        $reach = (float) ($data['r'] ?? 13) * 0.6;
                        $inside[] = [
                            'id' => $id . '-in' . $k,
                            'type' => 'game-ball',
                            'parent' => $id . '-birth',
                            'asset' => $row['asset'],
                            'member' => $row['asset'] . '-e',
                            'data' => [
                                'x' => WogGeometry::fixed(cos($angle) * $reach, 2),
                                'y' => WogGeometry::fixed(sin($angle) * $reach, 2),
                            ],
                        ];
                    }
                }
            }

            // Вспышка частиц: у лопающегося своя, у взрывающегося своя. Обе
            // выпускаются гибелью — тем же рождением, что и начинка со следом,
            // — и движку про них знать нечего.
            foreach ([['popFx', 'pop'], ['blastFx', 'blast']] as [$key, $tail]) {
                $name = (string) ($data[$key] ?? '');
                unset($data[$key]);
                $eff = $this->fx[$name] ?? null;

                if ($eff === null) {
                    continue;
                }

                $max = (float) ($eff['attrs']['maxparticles'] ?? 10);
                $rate = (float) ($eff['attrs']['rate'] ?? 0);
                $k = 0;

                foreach ($eff['parts'] as $part) {
                    $burst = WogLevel::burst($part["attrs"], $this->images, $this->root, $max, $rate);

                    if ($burst === null) {
                        continue;
                    }

                    $inside[] = [
                        'id' => $id . '-' . $tail . $k++,
                        'type' => 'sparks',
                        'parent' => $id . '-birth',
                        'data' => $burst['data'],
                    ];
                    $this->marks += $burst['media'];
                }
            }

            if ($mark !== '') {
                $side = (float) ($data['r'] ?? 13) * 2.4;
                $inside[] = [
                    'id' => $id . '-splat',
                    'type' => 'picture',
                    'parent' => $id . '-birth',
                    'data' => [
                        'x' => -$side / 2, 'y' => -$side / 2,
                        'w' => WogGeometry::fixed($side, 2),
                        'h' => WogGeometry::fixed($side, 2),
                        'rot' => 0.0, 'opacity' => 1.0, 'depth' => -1.0,
                        'src' => $mark,
                        // Все пятна, какие есть: какое достанется этому следу,
                        // решится при его появлении. Сорок одинаковых клякс на
                        // полу выглядели бы штампом, а не побоищем.
                        'srcs' => array_values($marks),
                        'fit' => 'contain', 'tint' => 0.0,
                        'tileW' => 0.0, 'tileH' => 0.0,
                        'flipX' => false, 'flipY' => false,
                        'anim' => [], 'animDur' => 0.0, 'animLoop' => true,
                    ],
                ];
                foreach ($marks as $one) {
                    $this->marks[$one] = $one;
                }
            }

            $assets[] = [
                'id' => $id,
                'title' => "Шар: {$type}",
                'entities' => [['id' => $id . '-e', 'type' => 'game-ball', 'data' => $data], ...$inside],
            ];
        }

        return [
            'format' => 'goo-bundle',
            'version' => 1,
            'kind' => 'library',
            'stories' => [[
                'id' => 'wog', 'title' => 'World of Goo (импорт)', 'cover' => '',
                'chapters' => array_column($chapters, 'id'), 'hot' => [],
                'start' => $chapters[0]['id'] ?? '',
            ]],
            'chapters' => $chapters,
            'levels' => $levels,
            // Ball types: 54 sets instead of 2706 copies. An asset never
            // changes, so a reference to one is a complete description rather
            // than a hopeful one.
            'assets' => $assets,
        ];
    }

    /** @return list<string> */
    /**
     * Что здесь уровень, а что просто лежит в папке уровней.
     *
     * Папка — не список. В ней рядом с уровнями лежат экраны карты
     * (`island1`…`island5`, `MapWorldView`, `IslandUi`), Корпорация — башня,
     * куда сносят собранное (`wogc`, `wogc3d`, `wogcd`), и забытая
     * разработчиками проба (`ThrusterTest`). Одиннадцать штук на сорок семь
     * настоящих.
     *
     * Отличать их по признакам уровня — по нулевой цели, по отсутствию трубы —
     * не выйдет: у `ProductLauncher` цель ноль и трубы нет, а это настоящий
     * уровень, он кончается по другому условию. Догадка тут промахнётся.
     *
     * Спрашивать надо у того, кто знает: острова и есть список того, во что
     * играют. Что не названо ни одним островом — не уровень.
     *
     * Половина отчёта о непереносимом приходила именно отсюда: подстановка
     * значения в подписи — это счётчик высоты башни, а пропавшее определение
     * шара — проба с несуществующим `thruster`. Ни то, ни другое к уровням
     * отношения не имеет.
     *
     * Если островов нет вовсе — берём всё, что лежит: чужой набор может быть
     * собран иначе, и молча отдать пустоту хуже, чем взять лишнее.
     *
     * @return list<string>
     */
    private function levelNames(string $root): array
    {
        $named = [];

        foreach (glob($root . '/islands/*') ?: [] as $file) {
            if (is_dir($file)) {
                continue;
            }

            $bytes = @file_get_contents($file);

            if ($bytes === false) {
                continue;
            }

            $isle = WogFile::parseXml(WogFile::decrypt($bytes));
            $mine = [];

            foreach (WogFile::kids($isle, 'level') as $one) {
                $id = $one['attrs']['id'] ?? '';

                if ($id === '') {
                    continue;
                }

                $named[$id] = true;
                $this->mark[$id] = self::markOf((string) ($one['attrs']['ocd'] ?? ''));
                $mine[] = [
                    'id' => $id,
                    'depends' => (string) ($one['attrs']['depends'] ?? ''),
                    // Название — ключ в таблице текстов, а не сам текст.
                    'name' => (string) ($one['attrs']['name'] ?? ''),
                    'text' => (string) ($one['attrs']['text'] ?? ''),
                    'cutscene' => (string) ($one['attrs']['cutscene'] ?? ''),
                    'oncomplete' => (string) ($one['attrs']['oncomplete'] ?? ''),
                ];
            }

            $this->islands[] = [
                'title' => (string) ($isle['attrs']['name'] ?? 'Остров'),
                'levels' => $mine,
            ];
        }

        $out = [];
        $skipped = [];

        foreach (scandir($root . '/levels') ?: [] as $name) {
            if ($name === '.' || $name === '..' || !is_dir($root . '/levels/' . $name)) {
                continue;
            }

            if ($named !== [] && !isset($named[$name])) {
                $skipped[] = $name;

                continue;
            }

            $out[] = $name;
        }

        sort($out);
        sort($skipped);

        if ($skipped !== []) {
            $this->skipped = $skipped;
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    /**
     * «balls,11», «time,16», «moves,14» — чем меряют и сколько.
     *
     * Мера сверяется со списком того, что мы считаем: попросили бы померить
     * незнакомое — это опечатка в наборе, и принять её значило бы пообещать
     * отличие, которого никогда не выдадут.
     *
     * @return array{by: string, value: float, required: bool}|null
     */
    private static function markOf(string $ocd): ?array
    {
        [$by, $value] = array_pad(explode(',', trim($ocd), 2), 2, '');
        $by = trim($by);

        if (!in_array($by, ['balls', 'time', 'moves'], true) || !is_numeric($value)) {
            return null;
        }

        return ['by' => $by, 'value' => (float) $value, 'required' => false];
    }

    /** @return array<string, array<string, mixed>> */
    private function ballDefinitions(string $root): array
    {
        $out = [];

        foreach (scandir($root . '/balls') ?: [] as $name) {
            $def = $this->read("{$root}/balls/{$name}/balls.xml.bin");

            if ($def !== null) {
                $out[$name] = $def;
            }
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function read(string $file): ?array
    {
        return is_file($file)
            ? WogFile::parseXml(WogFile::decrypt((string) file_get_contents($file)))
            : null;
    }
}
