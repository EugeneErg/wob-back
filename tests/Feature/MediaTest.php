<?php

declare(strict_types=1);

namespace Wob\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Wob\Identity\Application\DTO\GoogleIdentity;
use Wob\Identity\Application\Exception\AuthenticationFailed;
use Wob\Identity\Application\Port\GoogleIdentityVerifier;
use Wob\Tests\TestCase;

/**
 * Covers and intros live outside the bundle.
 *
 * The point of the whole module is that bytes never travel inside a library
 * export. A story carries the id of its intro; the video itself is fetched
 * once, cached forever, and belongs to whoever uploaded it.
 */
final class MediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A fake disk, so a test never writes into storage/app and never has to
        // clean up after itself.
        Storage::fake('local');

        $this->app->bind(GoogleIdentityVerifier::class, static fn (): GoogleIdentityVerifier => new class () implements GoogleIdentityVerifier {
            public function verify(string $credential): GoogleIdentity
            {
                return match ($credential) {
                    'author' => new GoogleIdentity('sub-1', 'author@example.com', true, 'Author', null),
                    'stranger' => new GoogleIdentity('sub-2', 'stranger@example.com', true, 'Stranger', null),
                    default => throw AuthenticationFailed::because('no'),
                };
            }
        });
    }

    public function testAnImageIsStoredAndComesBack(): void
    {
        $this->signIn('author');

        $created = $this->post('/api/media', ['file' => $this->pngFile('cover.png')])
            ->assertStatus(201)
            ->json();

        self::assertSame('image', $created['kind']);
        self::assertSame('cover.png', $created['name']);
        self::assertGreaterThan(0, $created['bytes']);

        $this->get($created['url'])
            ->assertOk()
            ->assertHeader('Content-Type', $created['mime']);
    }

    public function testTheBytesNeverLeaveTheirOwner(): void
    {
        $this->signIn('author');
        $url = $this->post('/api/media', ['file' => $this->pngFile('secret.png')])
            ->assertStatus(201)
            ->json('url');

        // A media id is a random UUID, but a random id is not a permission: an
        // unreleased intro belongs to an unreleased story.
        $this->signIn('stranger');
        $this->get($url)->assertStatus(403);
    }

    public function testAnUploadIsListedForItsAuthorOnly(): void
    {
        $this->signIn('author');
        $this->post('/api/media', ['file' => $this->pngFile('mine.png')])->assertStatus(201);

        self::assertCount(1, $this->getJson('/api/media')->assertOk()->json('media'));

        $this->signIn('stranger');
        self::assertCount(0, $this->getJson('/api/media')->assertOk()->json('media'));
    }

    /**
     * A format the game cannot play is refused at the door.
     *
     * The author finds out immediately and picks another file. The alternative
     * is a story that fails halfway through for a player who cannot fix it.
     */
    #[DataProvider('unplayableFiles')]
    public function testAFormatTheGameCannotPlayIsRefused(string $name, string $mime): void
    {
        $this->signIn('author');

        $this->post('/api/media', ['file' => UploadedFile::fake()->createWithContent($name, 'not really media')])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid');
    }

    /** @return iterable<string, array{string, string}> */
    public static function unplayableFiles(): iterable
    {
        yield 'a document' => ['notes.pdf', 'application/pdf'];
        yield 'an archive' => ['stuff.zip', 'application/zip'];
        yield 'a script' => ['run.js', 'text/javascript'];
    }

    public function testAVideoOverTheCeilingIsRefused(): void
    {
        $this->signIn('author');

        // A real mp4 header padded out past the ceiling. It has to be a
        // genuine video, or the upload would be refused for its format and the
        // test would pass without ever reaching the size check it is named for.
        $this->post('/api/media', ['file' => $this->mp4File('intro.mp4', 61 * 1024 * 1024)])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid');
    }

    /**
     * Sound uploads, and shows up on the shelf beside the pictures.
     *
     * It could not be uploaded at all while the only kinds were pictures and
     * video, which is why a ball had no sound for any of its two dozen events
     * and a level had no music. The shelf matters as much as the ceiling: one
     * strand sound belongs to fifty balls, and re-uploading it fifty times is
     * not an inconvenience, it is what makes the feature unusable.
     */
    public function testASoundUploadsAndJoinsTheShelf(): void
    {
        $this->signIn('author');

        $made = $this->post('/api/media', ['file' => $this->oggFile('knock.ogg')])
            ->assertStatus(201)->json();

        self::assertSame('sound', $made['kind']);
        self::assertSame('knock.ogg', $made['name']);

        $this->post('/api/media', ['file' => $this->pngFile('body.png')])->assertStatus(201);

        $shelf = $this->getJson('/api/media')->assertOk()->json('media');
        $kinds = array_column($shelf, 'kind');
        sort($kinds);

        self::assertSame(['image', 'sound'], $kinds);
    }

    /**
     * Sound shares the video ceiling rather than getting a number of its own.
     *
     * The same path carries a half-second knock and several minutes of music,
     * and there is no one size right for both. The ceiling is there to stop a
     * mistake, not to shape a decision: the largest file in the reference set
     * is 1.33 MB, well under it.
     */
    public function testASoundOverTheCeilingIsRefused(): void
    {
        $this->signIn('author');

        $this->post('/api/media', ['file' => $this->oggFile('epic.ogg', 61 * 1024 * 1024)])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid');
    }

    public function testUploadingNeedsASession(): void
    {
        $this->post('/api/media', ['file' => $this->pngFile('x.png')])
            ->assertStatus(401);
    }

    /** A real one-pixel PNG, so no image library is needed to make one. */
    private function pngFile(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wob');
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        ));

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    /** An mp4 that is only a header: sparse padding, so the size costs nothing. */
    private function mp4File(string $name, int $bytes): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wob');
        $handle = fopen($path, 'wb');
        fwrite($handle, "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41");
        ftruncate($handle, $bytes);
        fclose($handle);

        return new UploadedFile($path, $name, 'video/mp4', null, true);
    }

    /** An ogg that is only a header: sparse padding, so the size costs nothing. */
    private function oggFile(string $name, int $bytes = 0): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wob');
        $handle = fopen($path, 'wb');
        // A real Ogg page header followed by the Vorbis identification packet.
        // It has to be genuine: the format is read from the bytes, not from
        // what the upload claims, so a made-up header would be refused for its
        // format and the size test would pass without reaching the size check.
        fwrite($handle, "OggS\x00\x02" . str_repeat("\x00", 8) . "\x01\x00\x00\x00"
            . str_repeat("\x00", 4) . "\x00\x00\x00\x00\x01\x1e\x01vorbis"
            . "\x00\x00\x00\x00\x01\x44\xac\x00\x00" . str_repeat("\x00", 16));

        if ($bytes > 0) {
            ftruncate($handle, $bytes);
        }

        fclose($handle);

        return new UploadedFile($path, $name, 'audio/ogg', null, true);
    }

    private function signIn(string $who): void
    {
        $this->postJson('/api/auth/google', ['credential' => $who])->assertOk();
    }
}
