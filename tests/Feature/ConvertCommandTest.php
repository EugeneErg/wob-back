<?php

declare(strict_types=1);

namespace Wob\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Wob\Tests\TestCase;

/**
 * The two halves of importing, and the file between them.
 *
 * `wob:convert` reads someone else's format and writes ours; `wob:import`
 * takes ours and loads it. The split is the point: when an import goes wrong
 * there is now a bundle to open and look at, instead of a report saying how
 * many things did not make it.
 *
 * The fixture is a real level from the game, with real geometry, decoration,
 * balls and a camera. A made-up one would agree with whatever the converter
 * happens to do.
 */
final class ConvertCommandTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = __DIR__ . '/../Fixtures/wog';

    public function testAFolderOfGameFilesBecomesABundle(): void
    {
        $out = $this->tempFile();

        $this->artisan('wob:convert', ['source' => self::ROOT, '--out' => $out, '--quiet-report' => true])
            ->assertSuccessful();

        $bundle = json_decode((string) file_get_contents($out), true);

        self::assertSame('goo-bundle', $bundle['format']);
        self::assertCount(1, $bundle['levels']);

        $level = $bundle['levels'][0];
        self::assertSame('wog-helloworld', $level['id']);
        self::assertGreaterThan(0, $level['width']);

        $kinds = array_count_values(array_column($level['entities'], 'type'));
        self::assertArrayHasKey('terrain', $kinds);
        self::assertArrayHasKey('picture', $kinds);
        self::assertArrayHasKey('game-ball', $kinds);
    }

    /**
     * A ball's settings live in an asset and the level names it.
     *
     * Copying them into every ball would repeat each set about fifty times over
     * a real story — nearly four megabytes of parts and sines — and "change the
     * eye on every ball of this type" would become fifty edits by hand.
     */
    public function testBallTypesBecomeAssetsAndTheBallsPointAtThem(): void
    {
        $out = $this->tempFile();
        $this->artisan('wob:convert', ['source' => self::ROOT, '--out' => $out, '--quiet-report' => true]);

        $bundle = json_decode((string) file_get_contents($out), true);
        self::assertNotEmpty($bundle['assets']);

        $ids = array_column($bundle['assets'], 'id');

        foreach ($bundle['levels'][0]['entities'] as $e) {
            if ($e['type'] !== 'game-ball') {
                continue;
            }

            self::assertContains($e['asset'], $ids);
            // The instance keeps only what is its own: where it stands, whether
            // it sleeps, what it is tied to.
            self::assertSame(['x', 'y', 'asleep', 'links'], array_keys($e['data']));
        }
    }

    public function testWhatDidNotComeAcrossIsPrintedByDefault(): void
    {
        // A converter that reports only success teaches you to trust it, and
        // this one leaves things behind on every run.
        $this->artisan('wob:convert', ['source' => self::ROOT, '--out' => $this->tempFile()])
            ->expectsOutputToContain('НЕ ПЕРЕНЕСЕНО')
            ->assertSuccessful();
    }

    public function testAFolderThatIsNotTheGameIsRefused(): void
    {
        $this->artisan('wob:convert', ['source' => sys_get_temp_dir(), '--out' => $this->tempFile()])
            ->assertFailed();
    }

    /** The two halves meet: what one writes, the other loads. */
    public function testTheBundleTheConverterWritesLoads(): void
    {
        $out = $this->tempFile();
        $this->artisan('wob:convert', ['source' => self::ROOT, '--out' => $out, '--quiet-report' => true]);

        $this->artisan('wob:import', ['file' => $out, '--user' => 'a@example.test'])
            ->assertSuccessful();

        self::assertSame(1, DB::table('levels')->count());
        self::assertGreaterThan(0, DB::table('assets')->count());

        // References survive the trip: a level naming an asset that is not
        // there would be refused, so arriving at all proves they resolved.
        $entities = json_decode((string) DB::table('levels')->value('entities'), true);
        $balls = array_filter($entities, static fn (array $e): bool => $e['type'] === 'game-ball');

        self::assertNotEmpty($balls);

        foreach ($balls as $ball) {
            self::assertArrayHasKey('asset', $ball);
        }
    }

    private function tempFile(): string
    {
        return tempnam(sys_get_temp_dir(), 'wob') . '.json';
    }
}
