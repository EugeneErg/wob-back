<?php

declare(strict_types=1);

namespace Wob\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Wob\Tests\TestCase;

/**
 * Importing from the command line, with the decisions it is allowed to make.
 *
 * The converter and the loader are two commands with a file between them, and
 * the file is the point: when an import goes wrong there is now something to
 * open and look at, instead of a report saying how many things did not make it.
 *
 * What this command may decide is deliberately narrow and deliberately visible
 * in --help: whose library it lands in, whether a release is published, and
 * whether the canon crown is worked out afterwards. None of those are offered
 * over the API, because they are decisions rather than edits.
 */
final class ImportCommandTest extends TestCase
{
    use RefreshDatabase;

    public function testABundleLandsInTheLibrary(): void
    {
        $file = $this->bundleFile();

        $this->artisan('wob:import', ['file' => $file, '--user' => 'importer@example.test'])
            ->expectsOutputToContain('stories 1, chapters 1, levels 1')
            ->assertSuccessful();

        self::assertSame(1, DB::table('stories')->count());
        self::assertSame(1, DB::table('levels')->count());
        self::assertSame(1, DB::table('assets')->count());

        // The author is made rather than demanded: the alternative is telling
        // somebody setting up a fresh machine to go and create a user by hand,
        // in a way the application never otherwise asks for.
        self::assertSame(1, DB::table('users')->where('email', 'importer@example.test')->count());

        // Nothing was released: that has to be asked for.
        self::assertSame(0, DB::table('releases')->count());
    }

    /**
     * A release stays shut to everyone but its author until the author has
     * cleared it, and --release is how an import says it counts as cleared.
     *
     * That is not a way around the rule. The rule is that a story its own maker
     * cannot finish is not ready for anyone else; the flag is someone deciding
     * that an imported story has been vouched for. It is named, it is in
     * --help, and it does nothing unless asked.
     */
    public function testReleasingOpensTheGateOnlyWhenAsked(): void
    {
        $file = $this->bundleFile();

        $this->artisan('wob:import', ['file' => $file, '--user' => 'a@example.test', '--release' => true])
            ->assertSuccessful();

        $release = DB::table('releases')->first();
        self::assertNotNull($release);
        self::assertNotNull($release->author_cleared_at);
    }

    public function testADryRunChangesNothing(): void
    {
        $this->artisan('wob:import', ['file' => $this->bundleFile(), '--dry-run' => true])
            ->expectsOutputToContain('Nothing was changed')
            ->assertSuccessful();

        self::assertSame(0, DB::table('stories')->count());
        self::assertSame(0, DB::table('users')->count());
    }

    public function testAFileThatIsNotABundleIsRefusedBeforeAnythingHappens(): void
    {
        $bad = tempnam(sys_get_temp_dir(), 'wob') . '.json';
        file_put_contents($bad, 'not json at all');

        $this->artisan('wob:import', ['file' => $bad])->assertFailed();
        $this->artisan('wob:import', ['file' => '/no/such/file.json'])->assertFailed();

        self::assertSame(0, DB::table('stories')->count());
    }

    /**
     * A level built on an asset the bundle does not carry is refused, and the
     * whole import is refused with it.
     *
     * Half an import is worse than none: the story shows up in the library and
     * falls apart when somebody opens it, long after the command said it went
     * fine.
     */
    public function testAnImportIsAllOrNothing(): void
    {
        $bundle = json_decode((string) file_get_contents($this->bundleFile()), false);
        $bundle->levels[0]->entities[0]->asset = 'no-such-asset';

        $broken = tempnam(sys_get_temp_dir(), 'wob') . '.json';
        file_put_contents($broken, json_encode($bundle));

        $this->artisan('wob:import', ['file' => $broken, '--user' => 'a@example.test'])->assertFailed();

        self::assertSame(0, DB::table('stories')->count());
        self::assertSame(0, DB::table('levels')->count());
    }

    /**
     * The files the bundle refers to arrive with it, and it ends up pointing at
     * them.
     *
     * This is the half that went missing when importing was split in two. A
     * bundle without it still imports, still reports the right numbers and
     * still looks correct in the database — every picture in it is simply a
     * path into somebody else's folder, and nothing finds out until a player
     * opens a level and sees a blank. So the check is not "did files upload"
     * but "is the old path gone", which is the part that stayed true while
     * being wrong.
     */
    public function testTheFilesABundleRefersToAreUploadedAndPointedAt(): void
    {
        Storage::fake('local');

        $file = $this->bundleFile('images/motor.png');
        $list = $this->mediaList(['motor' => 'images/motor.png']);

        $this->artisan('wob:import', [
            'file' => $file,
            '--user' => 'a@example.test',
            '--media' => $list,
            '--media-root' => __DIR__ . '/../Fixtures/wog',
        ])->expectsOutputToContain('files uploaded: 1')->assertSuccessful();

        $media = DB::table('media')->first();
        self::assertNotNull($media);
        self::assertSame('image', $media->kind);
        self::assertSame('motor.png', $media->original_name);

        $entities = (string) DB::table('levels')->value('entities');
        self::assertStringContainsString('/api/media/' . $media->id, $entities);
        self::assertStringNotContainsString('images/motor.png', $entities);
    }

    /**
     * A file that is not where the list says stops the import before anything
     * is written.
     *
     * Checked all at once and up front, because the usual cause is not one
     * missing picture but a --media-root pointing at the wrong folder — and
     * finding that out halfway through leaves a library's worth of uploads on
     * the disk and a message about a single path.
     */
    public function testAMissingFileStopsTheImportBeforeAnythingIsWritten(): void
    {
        Storage::fake('local');

        $list = $this->mediaList(['gone' => 'images/not-here.png']);

        $this->artisan('wob:import', [
            'file' => $this->bundleFile('images/not-here.png'),
            '--user' => 'a@example.test',
            '--media' => $list,
            '--media-root' => __DIR__ . '/../Fixtures/wog',
        ])->assertFailed();

        self::assertSame(0, DB::table('stories')->count());
        self::assertSame(0, DB::table('media')->count());
        self::assertSame(0, DB::table('users')->count());
    }

    /**
     * The paths in a media list are relative, so the folder they are relative
     * to is not optional.
     */
    public function testAMediaListWithoutARootIsRefused(): void
    {
        $this->artisan('wob:import', [
            'file' => $this->bundleFile('images/motor.png'),
            '--media' => $this->mediaList(['motor' => 'images/motor.png']),
        ])->assertFailed();

        self::assertSame(0, DB::table('stories')->count());
    }

    /** @param array<string, string> $named */
    private function mediaList(array $named): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wob') . '.json';
        file_put_contents($path, json_encode($named));

        return $path;
    }

    private function bundleFile(string $picture = ''): string
    {
        $bundle = [
            // Имена взяты из настоящего пакета, а не придуманы: я сперва
            // написал 'wob-bundle', и проверка падала на входной сверке формата.
            'format' => 'goo-bundle',
            'version' => 1,
            'kind' => 'library',
            'assets' => [[
                'id' => 'ball-common',
                'title' => 'Common ball',
                'entities' => [['id' => 'ball', 'type' => 'game-ball', 'data' => ['r' => 13]]],
            ]],
            'stories' => [[
                'id' => 'st-1', 'title' => 'A story', 'cover' => '#000',
                'chapters' => ['ch-1'], 'hot' => [], 'start' => 'ch-1',
            ]],
            // У главы обязателен УЗЕЛ на уровень, а не просто список имён:
            // уровень без узла некуда поставить на карте, и он тихо теряется.
            // Я сперва написал пустой nodes и получил ноль уровней в базе при
            // бодром «levels 1» в выводе — цифра из файла, а не из базы.
            'chapters' => [[
                'id' => 'ch-1', 'storyId' => 'st-1', 'title' => 'One', 'image' => '',
                'map' => '', 'canvas' => ['w' => 1600, 'h' => 900], 'hot' => [],
                'nodes' => [['id' => 'nd-1', 'levelId' => 'lv-1', 'x' => 10, 'y' => 10, 'next' => []]],
            ]],
            'levels' => [[
                'id' => 'lv-1', 'name' => 'A level',
                'width' => 1600, 'height' => 900,
                'gravity' => ['x' => 0, 'y' => 1800], 'goal' => 1, 'hot' => [],
                'entities' => [[
                    'id' => 'b-1', 'type' => 'game-ball',
                    'asset' => 'ball-common', 'data' => ['x' => 100, 'y' => 200],
                ], [
                    'id' => 'pic-1', 'type' => 'picture',
                    'data' => ['x' => 0, 'y' => 0, 'src' => $picture],
                ]],
            ]],
        ];

        $path = tempnam(sys_get_temp_dir(), 'wob') . '.json';
        file_put_contents($path, json_encode($bundle));

        return $path;
    }
}
