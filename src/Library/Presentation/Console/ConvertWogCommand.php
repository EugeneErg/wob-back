<?php

declare(strict_types=1);

namespace Wob\Library\Presentation\Console;

use Illuminate\Console\Command;
use Wob\Library\Infrastructure\Foreign\WogBallKind;
use Wob\Library\Infrastructure\Foreign\WogFile;
use Wob\Library\Infrastructure\Foreign\WogLevel;
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
        {--quiet-report : do not print what did not come across}';

    protected $description = 'Convert a folder of original game files into a wob bundle';

    /** @var array<string, array<string, int>> what did not come across, by level */
    private array $missed = [];

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
        $strings = WogTables::strings($root);
        $balls = $this->ballDefinitions($root);

        $levels = [];
        $kinds = [];
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

            $levels[] = $made['level'];
            $media += $made['media'];
            $kinds += $made['kinds'];
        }

        $bundle = $this->bundle($levels, $kinds);

        $out = (string) $this->option('out');
        file_put_contents($out, json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->line(sprintf(
            'уровней собрано: %d, видов шаров: %d, файлов к заливке: %d',
            count($levels),
            count($kinds),
            count($media),
        ));
        $this->line("записано: {$out}");

        $mediaOut = (string) $this->option('media');

        if ($mediaOut !== '') {
            file_put_contents($mediaOut, json_encode($media, JSON_UNESCAPED_SLASHES));
            $this->line("список файлов: {$mediaOut}");
        }

        if (!$this->option('quiet-report')) {
            $this->report();
        }

        return self::SUCCESS;
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
    private function bundle(array $levels, array $kinds): array
    {
        $nodes = [];
        $count = count($levels);

        foreach ($levels as $i => $l) {
            $nodes[] = [
                'id' => 'nd-' . $l['id'],
                'levelId' => $l['id'],
                'x' => 6 + ($i % 10) * 9,
                'y' => 10 + intdiv($i, 10) * 14,
                'next' => $i + 1 < $count ? ['nd-' . $levels[$i + 1]['id']] : [],
            ];
        }

        $assets = [];

        foreach ($kinds as $type => $data) {
            $id = 'wog-ball-' . mb_strtolower($type);
            $assets[] = [
                'id' => $id,
                'title' => "Шар: {$type}",
                'entities' => [['id' => $id . '-e', 'type' => 'game-ball', 'data' => $data]],
            ];
        }

        return [
            'format' => 'goo-bundle',
            'version' => 1,
            'kind' => 'library',
            'stories' => [[
                'id' => 'wog', 'title' => 'World of Goo (импорт)', 'cover' => '',
                'chapters' => ['wog-ch1'], 'hot' => [], 'start' => 'wog-ch1',
            ]],
            'chapters' => [[
                'id' => 'wog-ch1', 'storyId' => 'wog', 'title' => 'Всё подряд',
                'image' => '', 'map' => '', 'canvas' => ['w' => 1600, 'h' => 900],
                'hot' => [], 'nodes' => $nodes,
            ]],
            'levels' => $levels,
            // Ball types: 54 sets instead of 2706 copies. An asset never
            // changes, so a reference to one is a complete description rather
            // than a hopeful one.
            'assets' => $assets,
        ];
    }

    /** @return list<string> */
    private function levelNames(string $root): array
    {
        $out = [];

        foreach (scandir($root . '/levels') ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && is_dir($root . '/levels/' . $name)) {
                $out[] = $name;
            }
        }

        sort($out);

        return $out;
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
