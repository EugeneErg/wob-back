<?php

declare(strict_types=1);

namespace Wob\Tests\Unit\Library;

use PHPUnit\Framework\TestCase;
use Wob\Library\Infrastructure\Foreign\WogMovie;

/**
 * Заставка чужого набора, ставшая нашей картинкой с ходом времени.
 *
 * Фильм там собран, а не снят: несколько актёров, у каждого дорожка движения в
 * том же формате, что и всякая другая анимация набора. Здесь строится такой
 * фильм из двух актёров — картинки и строки — и проверяется, что из него
 * выходит.
 *
 * Меры экрана заданы в коде, а не в файле, и выведены они из самого набора:
 * чёрная заслонка там — картинка 32 на 32, растянутая ровно в 34.219 и 18.751
 * раза, то есть 1095 на 600. Поэтому и здесь актёр, поставленный в 547.5 и 300,
 * обязан оказаться ровно в середине.
 */
final class WogMovieTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wob-movie-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/art', 0o777, true);
        file_put_contents($this->dir . '/art/hill.png', self::png(64, 32));
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/art/hill.png');
        @unlink($this->dir . '/film.movie.binltl');
        @unlink($this->dir . '/film.resrc.bin');

        foreach (glob($this->dir . '/movie/film/*') ?: [] as $f) {
            @unlink($f);
        }

        @rmdir($this->dir . '/movie/film');
        @rmdir($this->dir . '/movie');
        @unlink($this->dir . '/music/theme.ogg');
        @rmdir($this->dir . '/music');
        @rmdir($this->dir . '/art');
        @rmdir($this->dir);
    }

    public function testAnActorIsPlacedByItsMiddleAndMovesOverTime(): void
    {
        $this->writeMovie(4.0, [
            ['image' => 'IMAGE_HILL', 'moves' => [[0.0, 547.5, 300.0], [4.0, 547.5, 100.0]]],
        ]);

        $svg = $this->build();

        self::assertNotNull($svg);
        // Экран чужой игры, не наш.
        self::assertStringContainsString('viewBox="0 0 1095 600"', $svg);
        // Картинка 64 на 32 сдвинута на половину себя: середина её и есть точка.
        self::assertStringContainsString('x="-32" y="-16" width="64" height="32"', $svg);
        // Картинка уехала внутрь файла, а не осталась ссылкой на что-то рядом.
        self::assertStringContainsString('xlink:href="data:image/png;base64,', $svg);
        // Движение — дорожкой по тем же кадрам, во всю длину фильма.
        self::assertStringContainsString('type="translate"', $svg);
        self::assertStringContainsString('values="547.5 300;547.5 100"', $svg);
        self::assertStringContainsString('dur="4s"', $svg);
        self::assertStringContainsString('keyTimes="0;1"', $svg);
    }

    public function testAWordActorCarriesTheSetsOwnText(): void
    {
        $this->writeMovie(2.0, [
            ['label' => '$MOVIE_END', 'moves' => [[0.0, 547.5, 300.0]]],
        ]);

        $svg = $this->build(['MOVIE_END' => "Конец главы\nи всей истории"]);

        self::assertNotNull($svg);
        // Текст остался текстом: его можно перевести и поправить, чего нельзя
        // было бы сделать, сняв заставку в видео.
        self::assertStringContainsString('Конец главы', (string) $svg);
        self::assertStringContainsString('и всей истории', (string) $svg);
        // Перенос строки в исходнике — вертикальная черта, и строк выходит две.
        self::assertSame(2, substr_count((string) $svg, '<tspan'));
        // Одна точка — это положение, а не движение, и дорожка всё равно должна
        // получить оба конца, иначе браузер её не сыграет.
        self::assertStringContainsString('keyTimes="0;1"', (string) $svg);
    }

    /**
     * Заставке нужен звук, а играть его картинка не умеет. Записан он тегом
     * `<audio>` из SVG 1.2 Tiny, а играет его клиент: хранить исполняемый
     * файл, приехавший в чужом пакете, нельзя.
     */
    public function testTheFilmComesOutAsAPageWithItsMusicInside(): void
    {
        $this->writeMovie(2.0, [['label' => '$MOVIE_END', 'moves' => [[0.0, 547.5, 300.0]]]]);
        mkdir($this->dir . '/music');
        file_put_contents($this->dir . '/music/theme.ogg', 'OggS-не-настоящая-песня');
        file_put_contents(
            $this->dir . '/film.resrc.bin',
            self::encrypted('<ResourceManifest><Resources id="movie_film">'
                . '<Sound id="SOUND_A" path="res/sounds/cheer" />'
                . '<Sound id="SOUND_B" path="res/music/theme" />'
                . '</Resources></ResourceManifest>'),
        );

        $html = WogMovie::toSvg($this->dir . '/film.movie.binltl', $this->dir, [], ['MOVIE_END' => 'Конец']);

        self::assertNotNull($html);
        // Звук записан тегом самого формата — SVG 1.2 Tiny, — а не свёртком
        // данных: браузер его не играет, но сказано в нём ровно то, что нужно.
        self::assertStringContainsString('<audio xlink:href="data:audio/ogg;base64,', (string) $html);
        self::assertStringContainsString('begin="0s" repeatCount="indefinite"', (string) $html);
        self::assertStringNotContainsString('<script', (string) $html);
        self::assertStringContainsString('Конец', (string) $html);

        // Реплики и шумы в страницу не попадают: момента у них нет. Их считают
        // отдельно, чтобы сказать о них вслух.
        self::assertSame(1, WogMovie::soundsBesidesMusic($this->dir, 'film'));
    }

    /**
     * Реплика звучит, когда её видно.
     *
     * Мига у звука в наборе нет, а у надписи есть — и надпись эта и есть та же
     * реплика словами. Голосов всегда меньше, чем надписей: кроме разговора в
     * заставке титры и название главы, — поэтому берутся первые по времени.
     */
    public function testAVoiceIsPlayedWhenItsSubtitleAppears(): void
    {
        $this->writeMovie(30.0, [
            ['label' => '$LINE_A', 'moves' => [[0.0, 0.0, 0.0]], 'shows' => 4.0],
            ['label' => '$LINE_B', 'moves' => [[0.0, 0.0, 0.0]], 'shows' => 9.0],
            ['label' => '$TITLE', 'moves' => [[0.0, 0.0, 0.0]], 'shows' => 25.0],
        ]);
        mkdir($this->dir . '/movie/film', 0o777, true);
        file_put_contents($this->dir . '/movie/film/dl_one.ogg', 'OggS-1');
        file_put_contents($this->dir . '/movie/film/dl_two.ogg', 'OggS-2');
        file_put_contents(
            $this->dir . '/film.resrc.bin',
            self::encrypted('<ResourceManifest><Resources id="movie_film">'
                . '<Sound id="S1" path="res/movie/film/dl_one" />'
                . '<Sound id="S2" path="res/movie/film/dl_two" />'
                . '</Resources></ResourceManifest>'),
        );

        $html = (string) WogMovie::toSvg($this->dir . '/film.movie.binltl', $this->dir, [], []);

        // Две реплики встали на две первые надписи, а титр остался без голоса.
        self::assertStringContainsString('begin="4s"', $html);
        self::assertStringContainsString('begin="9s"', $html);
        self::assertSame(2, substr_count($html, '<audio '));
        // Ни строки, которую можно исполнить: показ собирает клиент.
        self::assertStringNotContainsString('<script', $html);
        self::assertSame(2, WogMovie::placedVoices($this->dir . '/film.movie.binltl'));
    }

    public function testVoicesWithoutEnoughSubtitlesAreLeftAlone(): void
    {
        // Один субтитр на два голоса: кому какой — из данных не вывести, и
        // ставить наугад хуже, чем не ставить.
        $this->writeMovie(30.0, [['label' => '$LINE_A', 'moves' => [[0.0, 0.0, 0.0]], 'shows' => 4.0]]);
        mkdir($this->dir . '/movie/film', 0o777, true);
        file_put_contents($this->dir . '/movie/film/dlg_1.ogg', 'OggS-1');
        file_put_contents($this->dir . '/movie/film/dlg_2.ogg', 'OggS-2');
        file_put_contents(
            $this->dir . '/film.resrc.bin',
            self::encrypted('<ResourceManifest><Resources id="movie_film">'
                . '<Sound id="S1" path="res/movie/film/dlg_1" />'
                . '<Sound id="S2" path="res/movie/film/dlg_2" />'
                . '</Resources></ResourceManifest>'),
        );

        $html = (string) WogMovie::toSvg($this->dir . '/film.movie.binltl', $this->dir, [], []);

        self::assertStringNotContainsString('<audio ', $html);
        self::assertSame(0, WogMovie::placedVoices($this->dir . '/film.movie.binltl'));
        self::assertSame(2, WogMovie::soundsBesidesMusic($this->dir, 'film'));
    }

    public function testWhatIsNotAMovieIsRefusedRatherThanGuessed(): void
    {
        file_put_contents($this->dir . '/film.movie.binltl', 'не фильм');

        self::assertNull($this->build());
    }

    /** @param array<string, string> $strings */
    private function build(array $strings = []): ?string
    {
        return WogMovie::toSvg(
            $this->dir . '/film.movie.binltl',
            $this->dir,
            ['IMAGE_HILL' => 'art/hill'],
            $strings,
        );
    }

    /**
     * Фильм в двоичном виде: заголовок, актёры, их дорожки и таблица строк.
     *
     * @param list<array{image?: string, label?: string, moves: list<array{0: float, 1: float, 2: float}>}> $actors
     */
    private function writeMovie(float $length, array $actors): void
    {
        // Строки лежат подряд, разделённые нулём, и актёр называет смещение.
        $strings = "\0";
        $at = static function (string $word) use (&$strings): int {
            $found = strpos($strings, $word . "\0");

            if ($found === false) {
                $found = strlen($strings);
                $strings .= $word . "\0";
            }

            return $found;
        };

        $head = 20;
        $actorSize = 0x20;
        $pActors = $head;
        $pAnims = $pActors + count($actors) * $actorSize;
        $pAnimList = $pAnims + count($actors) * 4;

        $anims = '';
        $pointers = [];

        foreach ($actors as $one) {
            $pointers[] = $pAnimList + strlen($anims);
            $anims .= self::anim($one['moves'], $one['shows'] ?? null);
        }

        $pStrings = $pAnimList + strlen($anims);
        $rows = '';

        foreach ($actors as $one) {
            $rows .= pack(
                'llllggll',
                isset($one['label']) ? 1 : 0,
                $at($one['image'] ?? ''),
                $at($one['label'] ?? ''),
                $at(''),
                -1.0,
                -1.0,
                1,
                0,
            );
        }

        file_put_contents(
            $this->dir . '/film.movie.binltl',
            pack('g', $length) . pack('llll', count($actors), $pActors, $pAnims, $pStrings)
            . $rows
            . implode('', array_map(static fn (int $p): string => pack('l', $p), $pointers))
            . $anims
            . $strings,
        );
    }

    /**
     * Дорожка одного актёра. Смещения внутри неё считаются от её начала —
     * тем она и годится в общий файл.
     *
     * @param list<array{0: float, 1: float, 2: float}> $moves
     */
    private static function anim(array $moves, ?float $shows = null): string
    {
        $head = 44;
        $body = '';
        // Замыкание, а не стрелочная функция: та забирает тело по значению в
        // момент объявления, и все указатели вышли бы равны началу файла.
        $at = static function () use ($head, &$body): int {
            return $head + strlen($body);
        };

        $pTimes = $at();

        foreach ($moves as [$t]) {
            $body .= pack('g', $t);
        }

        $pTypes = $at();
        $body .= pack('l', 2); // перенос

        $slots = [];

        foreach ($moves as [, $x, $y]) {
            $slots[] = $at();
            $body .= pack('gg', $x, $y);
        }

        $pFrames = $at();

        foreach ($slots as $p) {
            $body .= pack('l', $p);
        }

        $pXform = $at();
        $body .= pack('l', $pFrames);

        // Прозрачность: до своего мига надпись невидима, после — видна. Так в
        // наборе и появляется всякая надпись, и по этому мигу ставится голос.
        $pAlpha = 0;

        if ($shows !== null) {
            $times = [0.0, $shows];
            $spots = [];

            foreach ([0, 255] as $value) {
                $spots[] = $at();
                $body .= pack('llll', 0, 0, 0, $value);
            }

            $pTimes = $at();

            foreach ($times as $t) {
                $body .= pack('g', $t);
            }

            $pAlpha = $at();

            foreach ($spots as $p) {
                $body .= pack('l', $p);
            }

            return pack('llllllllll', 0, 1, 0, 0, 0, count($times), $pTypes, $pTimes, 0, $pAlpha)
                . pack('l', 0) . $body;
        }

        return pack('llllllllll', 0, 0, 0, 1, 1, count($moves), $pTypes, $pTimes, $pXform, 0)
            . pack('l', 0) . $body;
    }

    /** Файлы набора лежат зашифрованными, и читает их конвертер только так. */
    private static function encrypted(string $xml): string
    {
        $key = (new \ReflectionClass(\Wob\Library\Infrastructure\Foreign\WogFile::class))->getConstant('KEY');
        $body = $xml . "\xFD";
        $body .= str_repeat("\x00", (16 - strlen($body) % 16) % 16);

        return (string) openssl_encrypt(
            $body,
            'aes-192-cbc',
            (string) $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            str_repeat("\0", 16),
        );
    }

    private static function png(int $w, int $h): string
    {
        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', $w, $h) . str_repeat("\0", 5);
    }
}
