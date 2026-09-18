<?php

declare(strict_types=1);

namespace Wob\Library\Infrastructure\Foreign;

use GdImage;

/**
 * Экраны выбора, а не уровни: карта мира и карты островов.
 *
 * Читаются они тем же файлом сцены, что и уровень, и поначалу именно поэтому
 * были выброшены: в списке уровней их нет, значит и разбирать нечего. Но мир —
 * это история, остров — глава, а кнопка на них — то место, куда автор поставил
 * главу или точку. Всё это в наборе есть, и было написано, что «раскладки точек
 * в данных нет». Есть: `levels/island1/island1.scene.bin`, группа
 * `levelMarkerGroup`, по кнопке на уровень со своими x, y и углом.
 *
 * ОТКУДА ЗДЕСЬ РИСОВАНИЕ КАРТИНОК
 *
 * У главы на доске одно поле под картинку и нет поля под угол — так решено, и
 * решено верно: кнопку не вращают, вращают картинку в редакторе. Но в наборе
 * острова стоят под углами (−52°, −110°, −172°, 124°, 63°), и если угол просто
 * забыть, пять островов встанут по стойке смирно и карта мира перестанет быть
 * собой. Поэтому угол запекается в сам PNG здесь, при переносе: наружу выходит
 * картинка, уже повёрнутая так, как её поставил автор оригинала.
 *
 * По той же причине здесь собирается задник. В сцене его рисуют два десятка
 * слоёв, а у главы поле под задник одно. Разнести одно на двадцать значило бы
 * завести «слои экрана» — устройство чужого движка внутри нашего; свести
 * двадцать в один PNG значит перенести то, что видно, и ничего не выдумать.
 *
 * ЧТО НЕ ПЕРЕНОСИТСЯ И ПОЧЕМУ
 *
 * Слои с `anim` крутятся: мельницы, шестерни, туман, вспышка. Застывший кадр
 * вместо вращения — это не «почти то же самое», это неподвижная мельница.
 * Такие слои пропускаются и о каждом сказано вслух.
 */
final class WogBoard
{
    /**
     * Чёрные полосы сверху и снизу — устройство чужого окна постоянного
     * размера. Своего окна у нас нет, доска тянется по содержимому, и полосы
     * легли бы поперёк неё двумя кляксами.
     */
    private const CHROME = ['letterbox_top', 'letterbox_bottom'];

    /**
     * Кнопки сцены: что на ней нажимают и где оно лежит.
     *
     * Ключ — `id` кнопки (`island1`, `lb_GoingUp`), потому что связать кнопку с
     * островом или уровнем можно только по нему: картинка у трёх точек из
     * двенадцати одна и та же.
     *
     * @param array<string, mixed>  $scene
     * @param array<string, string> $images
     *
     * @return array<string, array{
     *     x: float, y: float, w: float, h: float, rot: float, src: string,
     * }>
     */
    public static function buttons(array $scene, array $images, string $root): array
    {
        $out = [];

        foreach (self::allButtons($scene) as $button) {
            $a = $button['attrs'];
            $id = (string) ($a['id'] ?? '');
            // Цветная, а не та, что лежит в `up`.
            //
            // В оригинале у кнопки три картинки: `up` — чёрный силуэт, `over` —
            // цветной остров, `disabled` — снова силуэт. То есть остров там
            // чёрный всегда и расцветает только под курсором.
            //
            // У нас картинка одна, а чернит её фильтр — и чернит ровно тогда,
            // когда главы ещё нет. Взять `up` значило бы принести чёрное и
            // почернить ещё раз: открытая глава осталась бы силуэтом, и отличить
            // её от закрытой стало бы нельзя.
            $rel = $images[(string) ($a['over'] ?? '')] ?? $images[(string) ($a['up'] ?? '')] ?? null;
            $size = $rel === null ? null : WogFile::pngSize($root . '/' . $rel . '.png');

            if ($id === '' || $rel === null || $size === null) {
                continue;
            }

            $w = $size['w'] * (float) ($a['scalex'] ?? 1);
            $h = $size['h'] * (float) ($a['scaley'] ?? 1);
            $rot = (float) ($a['rotation'] ?? 0);
            [$rw, $rh] = self::spread($w, $h, $rot);

            $out[$id] = [
                // Там x,y — середина картинки, у нас — левый верхний угол; там
                // y растёт вверх, у нас вниз.
                'x' => WogGeometry::fixed((float) ($a['x'] ?? 0) - $rw / 2, 2),
                'y' => WogGeometry::fixed(-(float) ($a['y'] ?? 0) - $rh / 2, 2),
                'w' => WogGeometry::fixed($rw, 2),
                'h' => WogGeometry::fixed($rh, 2),
                'rot' => $rot,
                'src' => $rel . '.png',
            ];
        }

        return $out;
    }

    /**
     * Кнопки со всей сцены, и внутри групп тоже.
     *
     * Группа в оригинале — это про то, как по ним ходит джойстик (`osx="130,1.2"`),
     * а не про то, что они разные. `island6` лежит вне всякой группы, и делить
     * их по этому признаку значило бы потерять шестую главу.
     *
     * @param array<string, mixed> $scene
     *
     * @return list<array<string, mixed>>
     */
    private static function allButtons(array $scene): array
    {
        $out = WogFile::kids($scene, 'button');

        foreach (WogFile::kids($scene, 'buttongroup') as $group) {
            foreach (WogFile::kids($group, 'button') as $button) {
                $out[] = $button;
            }
        }

        return $out;
    }

    /**
     * Задник сцены — одной картинкой, собранной из её неподвижных слоёв.
     *
     * Возвращает и место: слои стоят там, где их поставил автор, а кнопки — в
     * тех же числах. Отдать картинку без рамки значило бы предложить читающему
     * гадать, куда её класть, и промахнуться ровно на столько, на сколько
     * задник несимметричен.
     *
     * @param array<string, mixed>       $scene
     * @param array<string, string>      $images
     * @param callable(string, int):void $say
     *
     * @return array{src: string, x: float, y: float, w: float, h: float}|null
     */
    public static function backdrop(
        array $scene,
        array $images,
        string $root,
        string $into,
        string $name,
        callable $say,
    ): ?array {
        $layers = [];
        $moving = 0;

        foreach (WogFile::kids($scene, 'SceneLayer') as $layer) {
            $a = $layer['attrs'];

            if (isset($a['anim'])) {
                $moving++;

                continue;
            }

            if (in_array((string) ($a['name'] ?? ''), self::CHROME, true)) {
                continue;
            }

            $rel = $images[(string) ($a['image'] ?? '')] ?? null;
            $file = $rel === null ? null : $root . '/' . $rel . '.png';

            if ($file === null || !is_file($file)) {
                continue;
            }

            $size = WogFile::pngSize($file);

            if ($size === null) {
                continue;
            }

            $layers[] = [
                'file' => $file,
                'depth' => (float) ($a['depth'] ?? 0),
                'x' => (float) ($a['x'] ?? 0),
                'y' => (float) ($a['y'] ?? 0),
                'w' => $size['w'] * (float) ($a['scalex'] ?? 1),
                'h' => $size['h'] * (float) ($a['scaley'] ?? 1),
                'rot' => (float) ($a['rotation'] ?? 0),
                'alpha' => (float) ($a['alpha'] ?? 1),
                'tint' => (string) ($a['colorize'] ?? '255,255,255'),
            ];
        }

        if ($moving > 0) {
            $say('слой экрана выбора вращается — в задник он не встал', $moving);
        }

        if ($layers === []) {
            return null;
        }

        // Глубже — раньше: задник кладётся первым, кусты поверх него.
        usort($layers, static fn (array $a, array $b): int => $a['depth'] <=> $b['depth']);

        return self::draw($layers, $into, $name);
    }

    /**
     * Картинка, повёрнутая так, как её поставил автор.
     *
     * Ничего не делает, когда угол нулевой: перерисовывать PNG ради поворота на
     * ноль значит терять качество там, где меняться нечему.
     */
    public static function turned(string $file, float $rot, string $into, string $name): ?string
    {
        if (abs($rot) < 0.01) {
            return $file;
        }

        $src = self::open($file);

        if ($src === null) {
            return null;
        }

        $turned = self::spin($src, $rot);
        $path = self::put($turned, $into, $name);
        imagedestroy($src);
        imagedestroy($turned);

        return $path;
    }

    /**
     * Дорисовать картинку до нужных пропорций прозрачным полем.
     *
     * Карта главы рисуется в рамке 16:9 и `background-size: cover`, то есть
     * лишнее срезается по краям. Если задник острова 1600×1100, срежется верх и
     * низ — а точки на нём стоят в долях рамки и никуда не сдвинутся. Точки
     * уедут с карты, и ничто об этом не скажет.
     *
     * Поэтому пропорции приводятся здесь, у картинки, а не подгоняются на той
     * стороне: дорисованное прозрачным поле ничего не закрывает, зато `cover`
     * на совпадающих пропорциях не срезает ничего.
     *
     * @param array{src: string, x: float, y: float, w: float, h: float} $box
     *
     * @return array{src: string, x: float, y: float, w: float, h: float}|null
     */
    public static function padded(array $box, float $ratio, string $into, string $name): ?array
    {
        $src = self::open($box['src']);

        if ($src === null) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $wide = (int) max($w, round($h * $ratio));
        $tall = (int) max($h, round($wide / $ratio));

        if ($wide === $w && $tall === $h) {
            imagedestroy($src);

            return $box;
        }

        $out = self::blank($wide, $tall);
        $dx = intdiv($wide - $w, 2);
        $dy = intdiv($tall - $h, 2);
        imagecopy($out, $src, $dx, $dy, 0, 0, $w, $h);
        $path = self::put($out, $into, $name);
        imagedestroy($src);
        imagedestroy($out);

        if ($path === null) {
            return null;
        }

        // Рамка выросла в обе стороны от середины, и место обязано поехать
        // вместе с ней: иначе задник встанет со сдвигом ровно на поле.
        return [
            'src' => $path,
            'x' => WogGeometry::fixed($box['x'] - $dx, 2),
            'y' => WogGeometry::fixed($box['y'] - $dy, 2),
            'w' => (float) $wide,
            'h' => (float) $tall,
        ];
    }

    /**
     * @param list<array{
     *     file: string, depth: float, x: float, y: float, w: float, h: float,
     *     rot: float, alpha: float, tint: string,
     * }> $layers
     *
     * @return array{src: string, x: float, y: float, w: float, h: float}|null
     */
    private static function draw(array $layers, string $into, string $name): ?array
    {
        $x0 = INF;
        $y0 = INF;
        $x1 = -INF;
        $y1 = -INF;

        foreach ($layers as $layer) {
            [$rw, $rh] = self::spread($layer['w'], $layer['h'], $layer['rot']);
            $x0 = min($x0, $layer['x'] - $rw / 2);
            $x1 = max($x1, $layer['x'] + $rw / 2);
            $y0 = min($y0, $layer['y'] - $rh / 2);
            $y1 = max($y1, $layer['y'] + $rh / 2);
        }

        $width = (int) ceil($x1 - $x0);
        $height = (int) ceil($y1 - $y0);

        if ($width <= 0 || $height <= 0) {
            return null;
        }

        $out = self::blank($width, $height);
        imagealphablending($out, true);

        foreach ($layers as $layer) {
            $one = self::open($layer['file']);

            if ($one === null) {
                continue;
            }

            $piece = self::sized($one, $layer['w'], $layer['h']);
            imagedestroy($one);
            self::paint($piece, $layer['tint'], $layer['alpha']);

            if (abs($layer['rot']) >= 0.01) {
                $piece = self::spin($piece, $layer['rot']);
            }

            imagecopy(
                $out,
                $piece,
                (int) round($layer['x'] - $x0 - imagesx($piece) / 2),
                // У них y растёт вверх, у картинки — вниз.
                (int) round($y1 - $layer['y'] - imagesy($piece) / 2),
                0,
                0,
                imagesx($piece),
                imagesy($piece),
            );
            imagedestroy($piece);
        }

        $path = self::put($out, $into, $name);
        imagedestroy($out);

        return $path === null ? null : [
            'src' => $path,
            'x' => WogGeometry::fixed($x0, 2),
            'y' => WogGeometry::fixed(-$y1, 2),
            'w' => (float) $width,
            'h' => (float) $height,
        ];
    }

    /** Габариты картинки после поворота: во что она теперь не влезает. */
    /** @return array{float, float} */
    private static function spread(float $w, float $h, float $rot): array
    {
        $a = deg2rad($rot);

        return [
            abs($w * cos($a)) + abs($h * sin($a)),
            abs($w * sin($a)) + abs($h * cos($a)),
        ];
    }

    /**
     * Поворот в сторону оригинала.
     *
     * `imagerotate` крутит против часовой в осях картинки, где y растёт вниз, а
     * у них y растёт вверх — знаки взаимно сокращаются, и угол идёт как есть.
     * Проверяется это не рассуждением: уровни уже переносят `rotation` как
     * `-rotation` в наши градусы (там y вниз и по часовой), и уровни видно.
     */
    private static function spin(GdImage $src, float $rot): GdImage
    {
        $clear = (int) imagecolorallocatealpha($src, 0, 0, 0, 127);
        $out = imagerotate($src, $rot, $clear);

        if ($out === false) {
            return $src;
        }

        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagedestroy($src);

        return $out;
    }

    private static function open(string $file): ?GdImage
    {
        $img = @imagecreatefrompng($file);

        if ($img === false) {
            return null;
        }

        imagealphablending($img, false);
        imagesavealpha($img, true);

        return $img;
    }

    private static function blank(int $w, int $h): GdImage
    {
        $img = imagecreatetruecolor($w, $h);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, (int) imagecolorallocatealpha($img, 0, 0, 0, 127));

        return $img;
    }

    private static function sized(GdImage $src, float $w, float $h): GdImage
    {
        $out = self::blank((int) max(1, round($w)), (int) max(1, round($h)));
        imagecopyresampled(
            $out,
            $src,
            0,
            0,
            0,
            0,
            imagesx($out),
            imagesy($out),
            imagesx($src),
            imagesy($src),
        );

        return $out;
    }

    /**
     * Краска и прозрачность слоя.
     *
     * Обе множители, а не замена: `colorize="0,0,0"` делает мельницу чёрной, не
     * стирая её, и `alpha="0.47"` делает облако полупрозрачным, не убирая его
     * собственных дырок.
     */
    private static function paint(GdImage $img, string $tint, float $alpha): void
    {
        $rgb = array_map('intval', explode(',', $tint));
        $r = $rgb[0] ?? 255;
        $g = $rgb[1] ?? 255;
        $b = $rgb[2] ?? 255;

        if ($r === 255 && $g === 255 && $b === 255 && $alpha >= 0.999) {
            return;
        }

        $w = imagesx($img);
        $h = imagesy($img);

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($img, $x, $y);
                $a = ($c >> 24) & 0x7F;

                if ($a === 127) {
                    continue;
                }

                imagesetpixel($img, $x, $y, (int) imagecolorallocatealpha(
                    $img,
                    (int) ((($c >> 16) & 0xFF) * $r / 255),
                    (int) ((($c >> 8) & 0xFF) * $g / 255),
                    (int) (($c & 0xFF) * $b / 255),
                    (int) min(127, 127 - (127 - $a) * $alpha),
                ));
            }
        }
    }

    private static function put(GdImage $img, string $into, string $name): ?string
    {
        if (!is_dir($into) && !mkdir($into, 0o777, true) && !is_dir($into)) {
            return null;
        }

        $path = rtrim($into, '/') . '/' . $name . '.png';

        return imagepng($img, $path) ? $path : null;
    }
}
