<?php

declare(strict_types=1);

namespace Wob\Library\Infrastructure\Foreign;

/**
 * Заставки чужого набора — и во что они превращаются.
 *
 * Фильм там не снят, а собран: несколько актёров, каждый либо картинка, либо
 * строка текста, и у каждого своя дорожка движения. Дорожка записана ровно тем
 * же способом, что и всякая другая анимация набора, поэтому читает её тот же
 * `WogAnim` — фильму нужен только заголовок со списком актёров и смещениями.
 *
 * ЧЕМ ОН СТАНОВИТСЯ
 *
 * Картинкой SVG с собственным ходом времени. Актёр — `<image>` или `<text>`,
 * дорожка — `<animateTransform>` и `<animate>` по тем же ключевым кадрам.
 * Никакого проигрывателя такому файлу не нужно: он играет сам, стоит его
 * показать, и его можно положить в носители рядом с картинками.
 *
 * Снимать фильм в видео было бы вернее по виду и хуже по всему остальному:
 * получился бы тяжёлый непрозрачный файл, который нельзя ни перевести на другой
 * язык, ни поправить. Здесь же и текст, и движение остаются тем, чем были.
 *
 * ЗВУК ЛЕЖИТ РЯДОМ С КАРТИНКОЙ, А НЕ ИГРАЕТ САМ
 *
 * Звука у SVG нет: своего тега у формата не было никогда, а вложенный `<audio>`
 * браузер не оживляет — проверено и картинкой, и отдельным документом. Сделать
 * из заставки страницу со сценарием было бы можно, но хранить её нельзя:
 * носители мы отдаём со своего домена, а заставка приезжает в чужом пакете, и
 * чужой сценарий оказался бы рядом с сессией игрока.
 *
 * Поэтому здесь файл БЕЗ ЕДИНОЙ ИСПОЛНЯЕМОЙ СТРОКИ. Звук записан тегом `<audio>`
 * из SVG 1.2 Tiny — там он и задуман: ссылка на звук, `begin` для мига,
 * `repeatCount` для круга, время в той же модели, что у анимации рядом.
 * Ни один браузер этот тег не играет, и это ничего не меняет: играет его наш
 * проигрыватель, читая те же три свойства. Зато запись стоит в словаре самого
 * формата, а не в самодельном свёртке, и понятна всякому, кто откроет файл.
 *
 * ЧЕГО В НЁМ НЕ БУДЕТ
 *
 * Цветовой дорожки: в наборе она есть у одного актёра из ста, а без неё файл
 * читается проще. Звука: он в заставке идёт своей дорожкой, а не частью фильма.
 */
final class WogMovie
{
    /**
     * Экран, на котором заставка нарисована.
     *
     * Мер этих в файле нет, но они в нём видны: чёрная заслонка в конце главы —
     * картинка 32 на 32, растянутая в 34.219 и 18.751 раза, то есть ровно
     * 1095 на 600. Туда же, в 547.5 по горизонтали, съезжают обе строки
     * «Конец главы» — а съезжают они, конечно, в середину.
     */
    private const W = 1095;
    private const H = 600;

    private const IMAGE = 0;

    /**
     * @param array<string, string> $images  id ресурса → путь без корня и расширения
     * @param array<string, string> $strings таблица текстов набора
     */
    /**
     * Звук заставки: музыка кругом и реплики, каждая со своим мигом.
     */
    private static function soundData(string $file, string $root): string
    {
        $out = '';
        $music = self::music($file, $root);

        if ($music !== '') {
            $out .= '<audio xlink:href="' . self::embed($music, 'audio/ogg')
                . '" begin="0s" repeatCount="indefinite"/>';
        }

        foreach (self::voiceCues($file, $root) as [$at, $clip]) {
            $out .= '<audio xlink:href="' . self::embed($clip, 'audio/ogg')
                . '" begin="' . self::round($at, 3) . 's"/>';
        }

        return $out;
    }

    /**
     * Реплики: голос и миг, в который он звучит.
     *
     * Мига у звука в наборе нет — но он есть у надписи, а надпись и есть та же
     * реплика словами. Реплика звучит, когда её видно: иначе субтитр показывал
     * бы одно, а голос говорил другое.
     *
     * Ставится это только там, где сходится счёт: сколько реплик — столько и
     * надписей, и тогда порядок связывает их однозначно. В `Chapter3End` так и
     * есть: `dl_whatisit` и `dl_itsfalling` при двух надписях, и имена файлов
     * прямо называют, что в них сказано. Где счёт не сходится — не ставим
     * ничего: в `Chapter3Mid` один субтитр на два голоса, и кому какой, из
     * данных не вывести.
     *
     * Звук ведётся от часов САМОЙ КАРТИНКИ, а не от таймера: свёрнутая вкладка
     * замедляет таймеры, но не трогает время рисунка, и голос разошёлся бы с
     * тем, что на экране.
     */
    public static function placedVoices(string $file): int
    {
        $lines = self::lineTimes($file);
        $clips = 0;

        foreach (self::sounds($file) as $path) {
            $name = basename($path);

            if (str_starts_with($name, 'dl_') || str_starts_with($name, 'dlg_')) {
                $clips++;
            }
        }

        return $clips > count($lines) ? 0 : $clips;
    }

    /**
     * @return list<array{0: float, 1: string}> миг и путь к звуку
     */
    private static function voiceCues(string $file, string $root): array
    {
        $lines = self::lineTimes($file);
        $clips = [];

        foreach (self::sounds($file) as $path) {
            $name = basename($path);

            if (str_starts_with($name, 'dl_') || str_starts_with($name, 'dlg_')) {
                $clips[] = $root . '/' . substr($path, 4) . '.ogg';
            }
        }

        // Надписей в заставке больше, чем реплик: кроме разговора там титры,
        // название главы, поздравление. Говорят первые — разговор идёт в начале,
        // а титры приходят под конец. Сколько голосов, столько первых надписей
        // и берём.
        //
        // Что это верно, видно по самому набору: в `Chapter3End` два голоса,
        // `dl_whatisit` и `dl_itsfalling`, а первые две надписи говорят «что
        // это?» и «оно падает» — те же слова в том же порядке. Где голосов
        // больше, чем надписей, не ставим ничего.
        if ($clips === [] || count($clips) > count($lines)) {
            return [];
        }

        $lines = array_slice($lines, 0, count($clips));

        $out = [];

        foreach ($clips as $i => $clip) {
            $out[] = [round($lines[$i], 3), $clip];
        }

        return $out;
    }

    /**
     * Когда каждая надпись появляется, по порядку времени.
     *
     * @return list<float>
     */
    private static function lineTimes(string $file): array
    {
        $bytes = (string) @file_get_contents($file);
        $i32 = static fn (int $o): int => (int) (unpack('l', substr($bytes, $o, 4))[1] ?? 0);
        $out = [];

        for ($k = 0; $k < $i32(4); $k++) {
            $at = $i32(8) + $k * 0x20;

            if ($i32($at) === self::IMAGE) {
                continue;
            }

            $tracks = WogAnim::read($file, $i32($i32(12) + $k * 4), true)['tracks'];

            foreach ($tracks['alpha'] ?? [] as [$time, $value]) {
                if ($value > 0.5) {
                    $out[] = $time;
                    break;
                }
            }
        }

        sort($out);

        return $out;
    }

    /**
     * Звуки, приложенные к заставке.
     *
     * @return list<string>
     */
    private static function sounds(string $file): array
    {
        $list = dirname($file) . '/' . basename($file, '.movie.binltl') . '.resrc.bin';
        $bytes = @file_get_contents($list);

        if ($bytes === false) {
            return [];
        }

        $out = [];

        foreach (WogFile::kids(WogFile::parseXml(WogFile::decrypt($bytes)), 'Resources') as $group) {
            foreach ($group['children'] ?? [] as $item) {
                if ($item['tag'] === 'Sound') {
                    $out[] = (string) ($item['attrs']['path'] ?? '');
                }
            }
        }

        return $out;
    }

    /**
     * Сколько у заставки звуков, кроме музыки: реплики, шумы, возгласы.
     *
     * Они есть, и их слышно в оригинале — «что это?», «уже скоро», радостный
     * вопль тянучек. Слова самих реплик мы переносим: они в заставке написаны
     * надписью. А вот голос поставить некуда — момента у него нет, и даже пары
     * «эта надпись — этот голос» из данных не вывести: в `Chapter3Mid` одна
     * надпись и два голоса.
     */
    public static function soundsBesidesMusic(string $folder, string $name): int
    {
        $bytes = @file_get_contents($folder . '/' . $name . '.resrc.bin');

        if ($bytes === false) {
            return 0;
        }

        $count = 0;

        foreach (WogFile::kids(WogFile::parseXml(WogFile::decrypt($bytes)), 'Resources') as $group) {
            foreach ($group['children'] ?? [] as $item) {
                if ($item['tag'] === 'Sound' && !str_starts_with($item['attrs']['path'] ?? '', 'res/music/')) {
                    $count++;
                }
            }
        }

        // Реплики, которые удалось поставить по надписям, уже не потеряны.
        return max(0, $count - self::placedVoices($folder . '/' . $name . '.movie.binltl'));
    }

    /**
     * Музыка заставки.
     *
     * Список звуков лежит не в фильме, а рядом с ним, и момента у них нет
     * ни у одного: их включала сама игра. Музыку это не мешает поставить —
     * она зациклена и играет всю заставку, начало у неё то же, что у фильма.
     * Остальное (реплики, разовые звуки) остаётся лежать, и конвертер о них
     * говорит вслух.
     */
    private static function music(string $file, string $root): string
    {
        $list = dirname($file) . '/' . basename($file, '.movie.binltl') . '.resrc.bin';
        $bytes = @file_get_contents($list);

        if ($bytes === false) {
            return '';
        }

        foreach (WogFile::kids(WogFile::parseXml(WogFile::decrypt($bytes)), 'Resources') as $group) {
            foreach ($group['children'] ?? [] as $item) {
                $path = $item['attrs']['path'] ?? '';

                if ($item['tag'] === 'Sound' && str_starts_with($path, 'res/music/')) {
                    return $root . '/' . substr($path, 4) . '.ogg';
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, string> $images  id ресурса → путь без корня и расширения
     * @param array<string, string> $strings таблица текстов набора
     */
    public static function toSvg(string $file, string $root, array $images, array $strings): ?string
    {
        $bytes = @file_get_contents($file);

        if ($bytes === false || strlen($bytes) < 20) {
            return null;
        }

        $i32 = static fn (int $o): int => (int) (unpack('l', substr($bytes, $o, 4))[1] ?? 0);
        $f32 = static fn (int $o): float => (float) (unpack('g', substr($bytes, $o, 4))[1] ?? 0);

        $length = $f32(0);
        $count = $i32(4);
        $pActors = $i32(8);
        $pAnims = $i32(12);
        $pStrings = $i32(16);

        if ($count <= 0 || $length <= 0) {
            return null;
        }

        $text = static function (int $at) use ($bytes, $pStrings): string {
            $from = $pStrings + $at;
            $end = strpos($bytes, "\0", $from);

            return $end === false ? '' : substr($bytes, $from, $end - $from);
        };

        $parts = [];

        for ($k = 0; $k < $count; $k++) {
            $at = $pActors + $k * 0x20;
            // Дорожки актёра лежат тем же заголовком, что и отдельный файл
            // анимации, и все смещения в нём отсчитываются от его начала —
            // значит хвост файла с этого места и есть такой файл.
            $anim = WogAnim::read($file, $i32($pAnims + $k * 4), true);

            $parts[] = self::actor(
                $i32($at) === self::IMAGE,
                self::picture($root, $images[$text($i32($at + 4))] ?? ''),
                self::line($strings, $text($i32($at + 8))),
                $anim['tracks'],
                $anim['blank'],
                $length,
            );
        }

        $w = self::W;
        $h = self::H;

        return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"'
            . " viewBox=\"0 0 {$w} {$h}\" width=\"{$w}\" height=\"{$h}\">"
            . "<rect width=\"{$w}\" height=\"{$h}\" fill=\"#000\"/>"
            . self::soundData($file, $root)
            . implode('', array_filter($parts))
            . '</svg>';
    }

    /**
     * Один актёр со своими дорожками.
     *
     * Порядок преобразований важен и обратен порядку чтения: сначала место,
     * потом поворот, потом растяжение — иначе повёрнутый актёр уезжает не туда.
     * SVG применяет их справа налево, поэтому и записаны они в этом порядке.
     *
     * @param array<string, list<array{0: float, 1: float}>> $tracks
     * @param list<float>                                    $blank  моменты, в которые актёра нет
     */
    private static function actor(bool $isImage, string $src, string $label, array $tracks, array $blank, float $length): string
    {
        if ($isImage && $src === '') {
            return '';
        }

        if (!$isImage && $label === '') {
            return '';
        }

        // Актёр стоит СЕРЕДИНОЙ в своей точке, а не углом: так же, как всё
        // остальное в этом наборе. Значит картинку надо сдвинуть на половину
        // её размера, а размер — прочитать у самой картинки.
        if ($isImage) {
            [$w, $h] = self::sizeOf($src);
            $body = '<image xlink:href="' . self::embed($src) . '"'
                . ' x="' . self::round(-$w / 2, 1) . '" y="' . self::round(-$h / 2, 1) . '"'
                . ' width="' . $w . '" height="' . $h . '"/>';
        } else {
            $body = '<text x="0" y="0" fill="#fff" font-size="26" text-anchor="middle"'
                . ' dominant-baseline="middle" font-family="serif">' . self::rows($label) . '</text>';
        }

        // Разрез по тем моментам, когда актёра нет: место не переходит через
        // них плавно. Яркость не режем — она и говорит, что актёра не видно.
        $moves = self::cut(self::pairs($tracks['dx'] ?? [], $tracks['dy'] ?? [], 0.0, 0.0), $blank);
        $scales = self::cut(self::pairs($tracks['sx'] ?? [], $tracks['sy'] ?? [], 1.0, 1.0), $blank);
        $turns = self::cut($tracks['rot'] ?? [], $blank);
        $fades = self::stay($tracks['alpha'] ?? [], $blank);

        $out = '<g>';

        // Каждое преобразование — свой слой. Складывать их в один нельзя: у
        // каждого свои моменты, и общего расписания у них нет.
        $depth = 0;

        foreach ([
            ['translate', $moves],
            ['rotate', array_map(static fn (array $k): array => [$k[0], -$k[1]], $turns)],
            ['scale', $scales],
        ] as [$kind, $keys]) {
            if ($keys === []) {
                continue;
            }

            $depth++;
            $out .= '<g>' . self::transform($kind, $keys, $length);
        }

        if ($fades !== []) {
            $depth++;
            $out .= '<g opacity="' . self::round($fades[0][1]) . '">'
                . self::track('opacity', $fades, $length, static fn (array $k): string => self::round($k[1]));
        }

        return $out . $body . str_repeat('</g>', $depth + 1);
    }

    /**
     * Дорожка преобразования.
     *
     * @param list<array{0: float, 1: float}|array{0: float, 1: float, 2: float}> $keys
     */
    private static function transform(string $kind, array $keys, float $length): string
    {
        $value = static function (array $k) use ($kind): string {
            $first = self::round($k[1]);

            return $kind === 'rotate' ? $first : $first . ' ' . self::round($k[2] ?? $k[1]);
        };

        return '<animateTransform attributeName="transform" type="' . $kind . '" additive="sum"'
            . self::timing($keys, $length, $value) . '/>';
    }

    /**
     * @param list<array{0: float, 1: float}> $keys
     * @param callable(array<int, float>): string $value
     */
    private static function track(string $name, array $keys, float $length, callable $value): string
    {
        return '<animate attributeName="' . $name . '"' . self::timing($keys, $length, $value) . '/>';
    }

    /**
     * Моменты и значения, как их понимает SVG: доли от общей длины и список
     * значений через точку с запятой.
     *
     * @param list<array<int, float>> $keys
     * @param callable(array<int, float>): string $value
     */
    private static function timing(array $keys, float $length, callable $value): string
    {
        $times = [];
        $values = [];

        foreach ($keys as $k) {
            $times[] = self::round(min(1.0, max(0.0, $k[0] / $length)), 4);
            $values[] = $value($k);
        }

        if ($times === []) {
            return ' dur="' . self::round($length, 3) . 's" fill="freeze" repeatCount="1"';
        }

        // Первый момент обязан быть нулём, последний — единицей, иначе браузер
        // отказывается играть дорожку целиком.
        //
        // Но добиваться этого переписыванием краёв нельзя: дорожка, начавшаяся
        // позже фильма или кончившаяся раньше, от этого растягивается на всю
        // его длину — и то, что делалось за треть секунды, ползёт пять секунд.
        // Края надо не двигать, а достраивать: до первого своего кадра актёр
        // стоит там же, где в первом, после последнего — там же, где в
        // последнем. Заодно это и есть случай дорожки из одного значения:
        // одна точка — не движение, а положение.
        if ($times[0] !== '0') {
            array_unshift($times, '0');
            array_unshift($values, $values[0]);
        }

        if ($times[count($times) - 1] !== '1') {
            $times[] = '1';
            $values[] = $values[count($values) - 1];
        }

        return ' dur="' . self::round($length, 3) . 's" fill="freeze" repeatCount="1"'
            . ' keyTimes="' . implode(';', $times) . '"'
            . ' values="' . implode(';', $values) . '"';
    }

    /**
     * Две дорожки, слитые в одну пару значений.
     *
     * Они ходят по своим моментам — у одной кадр есть, у другой нет, — поэтому
     * моменты объединяются, а недостающее берётся выборкой по времени: так
     * делает и сам набор, когда сводит движение в наши ключи.
     *
     * @param list<array{0: float, 1: float}> $xs
     * @param list<array{0: float, 1: float}> $ys
     *
     * @return list<array{0: float, 1: float, 2: float}>
     */
    private static function pairs(array $xs, array $ys, float $noX, float $noY): array
    {
        if ($xs === [] && $ys === []) {
            return [];
        }

        $times = [];

        foreach ([...$xs, ...$ys] as $k) {
            $times[(string) $k[0]] = $k[0];
        }

        sort($times);
        $out = [];

        foreach ($times as $t) {
            $out[] = [
                $t,
                $xs === [] ? $noX : WogAnim::sample($xs, $t),
                $ys === [] ? $noY : WogAnim::sample($ys, $t),
            ];
        }

        return $out;
    }

    /**
     * Разрез в тех местах, где актёра нет.
     *
     * Пропасть между двумя кусками жизни актёра — не путь. Он гаснет в одном
     * месте и загорается в другом, и между ними не едет: читатель формата уже
     * выбросил пустые кадры, но дорожка без них тянется из первого места во
     * второе насквозь, и актёр проползает это расстояние, пока проявляется.
     * По набору такой переезд — 644 px по середине при ширине экрана 1095.
     *
     * Лечится тем, что место меняется РАЗОМ и делает это тогда, когда актёра
     * не видно: до пропасти держится прежнее, от неё — следующее. Два ключа в
     * один и тот же миг SVG и читает как мгновенную смену.
     *
     * @param list<array<int, float>> $keys
     * @param list<float>             $blank
     *
     * @return list<array<int, float>>
     */
    private static function cut(array $keys, array $blank): array
    {
        if (count($keys) < 2 || $blank === []) {
            return $keys;
        }

        $out = [];

        foreach ($keys as $i => $key) {
            $out[] = $key;
            $next = $keys[$i + 1] ?? null;

            if ($next === null) {
                continue;
            }

            foreach ($blank as $at) {
                if ($at <= $key[0] || $at >= $next[0]) {
                    continue;
                }

                $hold = $key;
                $jump = $next;
                $hold[0] = $at;
                $jump[0] = $at;
                $out[] = $hold;
                $out[] = $jump;

                break;
            }
        }

        return $out;
    }

    /**
     * Пустой кадр не несёт ни места, ни яркости.
     *
     * Место в нём мы уже не берём за место — см. `cut`. Яркость в нём нельзя
     * брать за яркость ровно по той же причине: это не «стало прозрачно», а
     * «отсюда актёра нет». Разница видна на промежутках, а они здесь не
     * кадровые.
     *
     * Тянуть яркость К такому кадру — значит гасить актёра весь промежуток.
     * Фон главы `Chapter2End` виден с 3.6 секунды, а следующий его кадр пуст
     * на 68.8 — и фон затухает шестьдесят пять секунд.
     *
     * Тянуть ОТ него — значит проявлять актёра весь промежуток. Строка титра
     * в `Chapter1End` приходит на 14.7 секунде и проявляется все четырнадцать.
     * По набору таких проявлений 360, и середина у них 6.1 секунды.
     *
     * Поэтому с обеих сторон разом: до пустого кадра держится прежняя яркость,
     * в сам кадр актёр гаснет мгновенно, дальше держится ноль до первого
     * настоящего кадра, и там яркость берётся разом.
     *
     * Настоящее затухание и настоящее проявление от этого не страдают: автор
     * пишет их кадром с НАСТОЯЩИМ местом и малой яркостью, а такой кадр не
     * пустой и сюда не попадает. В том же `Chapter2End` актёр `LF` уходит
     * именно так — 150 на своём месте, и только следующий кадр пуст. Ценой
     * идут короткие сходы в пустоту, их середина 0.12 секунды: они
     * превращаются в мгновенные. Отличить их от шестидесятипятисекундных можно
     * было бы только порогом, а порог здесь — выдумка, которой в данных нет.
     *
     * @param list<array{0: float, 1: float}> $keys
     * @param list<float>                     $blank
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function stay(array $keys, array $blank): array
    {
        if (count($keys) < 2 || $blank === []) {
            return $keys;
        }

        $empty = array_flip(array_map(static fn (float $t): string => (string) $t, $blank));
        $out = [];
        $prev = null;

        foreach ($keys as $i => $key) {
            if (!isset($empty[(string) $key[0]])) {
                $out[] = $key;
                $prev = $key[1];

                continue;
            }

            // Держим прежнюю яркость до этого мига — и гаснем в нём разом.
            if ($prev !== null && $prev > 0.0) {
                $out[] = [$key[0], $prev];
            }

            $out[] = [$key[0], 0.0];
            $prev = 0.0;

            // Ноль держится до первого настоящего кадра, там — разом.
            $next = $keys[$i + 1] ?? null;

            if ($next !== null && !isset($empty[(string) $next[0]])) {
                $out[] = [$next[0], 0.0];
            }
        }

        return $out;
    }

    /**
     * Размер картинки. Читается прямо из заголовка PNG: тащить ради двух чисел
     * работу с изображениями было бы дороже, чем прочесть восемь байт.
     *
     * @return array{0: int, 1: int}
     */
    private static function sizeOf(string $path): array
    {
        $head = @file_get_contents($path, false, null, 16, 8);

        if ($head === false || strlen($head) < 8) {
            return [0, 0];
        }

        $size = unpack('Nw/Nh', $head);

        return [(int) $size['w'], (int) $size['h']];
    }

    /**
     * Путь к картинке актёра. В таблице ресурсов он записан без корня и без
     * расширения — расширение у набора всегда png.
     */
    private static function picture(string $root, string $rel): string
    {
        return $rel === '' ? '' : $root . '/' . $rel . '.png';
    }

    /**
     * Текст актёра: ключ таблицы, а не сама строка.
     *
     * @param array<string, string> $strings
     */
    private static function line(array $strings, string $key): string
    {
        $key = ltrim($key, '$');

        return $key === '' ? '' : ($strings[$key] ?? '');
    }

    /** Перенос строки в исходнике — вертикальная черта. */
    private static function rows(string $text): string
    {
        $rows = explode("\n", $text);
        $out = '';

        foreach (array_values($rows) as $i => $row) {
            $out .= '<tspan x="0" dy="' . ($i === 0 ? '0' : '32') . '">'
                . htmlspecialchars($row, ENT_QUOTES | ENT_XML1) . '</tspan>';
        }

        return $out;
    }

    /**
     * Картинка внутрь файла.
     *
     * Ссылкой на носитель было бы легче, но тогда заставка перестаёт быть одним
     * файлом: её нельзя ни отдать, ни показать, не притащив с собой четыре
     * картинки и не зная, где они лежат. Весит это немного — самая тяжёлая
     * заставка набора чуть больше мегабайта.
     */
    private static function embed(string $path, string $type = 'image/png'): string
    {
        $bytes = @file_get_contents($path);

        if ($bytes === false) {
            return '';
        }

        return 'data:' . $type . ';base64,' . base64_encode($bytes);
    }

    private static function round(float $v, int $places = 2): string
    {
        return rtrim(rtrim(number_format($v, $places, '.', ''), '0'), '.') ?: '0';
    }
}
