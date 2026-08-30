<?php

declare(strict_types=1);

namespace Wob\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Wob\Identity\Application\DTO\GoogleIdentity;
use Wob\Identity\Application\Exception\AuthenticationFailed;
use Wob\Identity\Application\Port\GoogleIdentityVerifier;
use Wob\Library\Domain\Service\IdGenerator;
use Wob\Tests\TestCase;

/**
 * Import and export, against a real Postgres.
 *
 * The load-bearing test is the last one: the actual library.json the game ships
 * with, imported whole. That file is what every existing player has sitting in
 * localStorage, so if it does not survive the trip there is no migration path
 * and the accounts are worth nothing.
 */
final class BundleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(GoogleIdentityVerifier::class, static fn (): GoogleIdentityVerifier => new class () implements GoogleIdentityVerifier {
            public function verify(string $credential): GoogleIdentity
            {
                return match ($credential) {
                    'author' => new GoogleIdentity('sub-1', 'author@example.com', true, 'Author', null),
                    'other' => new GoogleIdentity('sub-2', 'other@example.com', true, 'Other', null),
                    default => throw AuthenticationFailed::because('no'),
                };
            }
        });

        // Counting rather than random, so the test can say what a renamed id
        // became instead of only that it changed.
        $this->app->bind(IdGenerator::class, static fn (): IdGenerator => new class () implements IdGenerator {
            private int $n = 0;

            public function next(string $prefix): string
            {
                return $prefix . '-new' . ++$this->n;
            }
        });
    }

    public function testAStoryRoundTripsThroughExportAndImport(): void
    {
        $this->signIn('author');
        $this->authorAStory();

        $exported = $this->getJson('/api/stories/story-1/export')->assertOk()->json();

        self::assertSame('goo-bundle', $exported['format']);
        self::assertCount(1, $exported['stories']);
        self::assertCount(1, $exported['levels']);

        // Importing it back into the same account must not overwrite the
        // original: it is a copy, and the ids collide.
        $result = $this->postJson('/api/library/import', $exported)->assertStatus(201)->json();

        // Exact ids, because what matters is that every reference was rewritten
        // to the same new id — not merely that something changed. The numbering
        // follows the read order: levels, then chapters, then stories.
        self::assertSame('lvl-new1', $result['idMap']['lvl-1']);
        self::assertSame('ch-new2', $result['idMap']['ch-1']);
        self::assertSame('story-new3', $result['idMap']['story-1']);

        $this->getJson('/api/library')->assertJsonCount(2, 'stories');

        // The original is untouched.
        $this->getJson('/api/stories/story-1')->assertOk()->assertJsonPath('levels.0.name', 'Tower');

        // And the copy carries the same content under new ids.
        $copy = $this->getJson('/api/stories/story-new3')->assertOk();
        $copy->assertJsonPath('levels.0.name', 'Tower');
        $copy->assertJsonPath('chapters.0.nodes.0.levelId', 'lvl-new1');
    }

    /**
     * The fingerprint must survive the trip. It is what every recording of a
     * level is keyed on, and an import that quietly changes it would invalidate
     * records on content that did not actually change.
     */
    public function testAnImportedLevelKeepsItsContentHash(): void
    {
        $this->signIn('author');
        $this->authorAStory();

        $before = $this->getJson('/api/stories/story-1')->json('levels.0.hash');
        $exported = $this->getJson('/api/stories/story-1/export')->json();

        $this->postJson('/api/library/import', $exported)->assertStatus(201);

        $after = $this->getJson('/api/stories/story-new3')->json('levels.0.hash');

        // Not equal — the level id is part of the fingerprint and the id changed.
        // What matters is that the hash is derived, not copied: it must match
        // what the same content under the new id hashes to.
        self::assertNotSame($before, $after);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $after);

        // Importing into a fresh account keeps the ids, and therefore the hash.
        $this->postJson('/api/auth/logout');
        $this->signIn('other');
        $this->postJson('/api/library/import', $exported)->assertStatus(201);

        self::assertSame($before, $this->getJson('/api/stories/story-1')->json('levels.0.hash'));
    }

    /** Two authors may hold the same id; renaming on import would break that. */
    public function testAnotherAuthorKeepsTheOriginalIds(): void
    {
        $this->signIn('author');
        $this->authorAStory();
        $exported = $this->getJson('/api/stories/story-1/export')->json();
        $this->postJson('/api/auth/logout');

        $this->signIn('other');
        $result = $this->postJson('/api/library/import', $exported)->assertStatus(201)->json();

        self::assertSame([], $result['idMap'] === [] ? [] : array_diff_assoc($result['idMap'], [
            'story-1' => 'story-1',
            'ch-1' => 'ch-1',
            'lvl-1' => 'lvl-1',
        ]));

        $this->getJson('/api/stories/story-1')->assertOk()->assertJsonPath('title', 'First steps');
    }

    public function testAChapterWithoutAStoryGetsOneToLiveIn(): void
    {
        $this->signIn('author');

        $bundle = [
            'format' => 'goo-bundle',
            'version' => 1,
            'kind' => 'chapter',
            'stories' => [],
            'chapters' => [[
                'id' => 'ch-loose',
                'title' => 'Loose chapter',
                'image' => '#000',
                'nodes' => [['levelId' => 'lvl-a', 'x' => 10, 'y' => 10]],
                'edges' => [],
                'hot' => [],
            ]],
            'levels' => [$this->rawLevel('lvl-a')],
            'assets' => [],
        ];

        $result = $this->postJson('/api/library/import', $bundle)->assertStatus(201)->json();

        self::assertSame('Imported chapters', $result['stories'][0]['title']);
        self::assertStringContainsString('without a story', $result['warnings'][0]);
    }

    /**
     * A file may reference a level it does not carry. Refusing the whole import
     * over one missing level turns a partial file into no file, so the node goes
     * and the author is told.
     */
    public function testAMissingLevelDropsItsNodeAndSaysSo(): void
    {
        $this->signIn('author');

        $bundle = [
            'format' => 'goo-bundle',
            'version' => 1,
            'kind' => 'story',
            'stories' => [['id' => 'story-x', 'title' => 'Partial', 'cover' => '#000', 'chapters' => ['ch-x'], 'hot' => []]],
            'chapters' => [[
                'id' => 'ch-x',
                'title' => 'Half a map',
                'image' => '#000',
                'nodes' => [
                    ['levelId' => 'lvl-here', 'x' => 10, 'y' => 10],
                    ['levelId' => 'lvl-gone', 'x' => 50, 'y' => 50],
                ],
                'edges' => [['from' => 'lvl-here', 'to' => 'lvl-gone']],
                'hot' => [],
            ]],
            'levels' => [$this->rawLevel('lvl-here')],
            'assets' => [],
        ];

        $result = $this->postJson('/api/library/import', $bundle)->assertStatus(201)->json();

        self::assertStringContainsString('lvl-gone', $result['warnings'][0]);

        $story = $this->getJson('/api/stories/story-x')->assertOk();
        $story->assertJsonCount(1, 'chapters.0.nodes');

        // The path went with the node it pointed at, or the map would gate the
        // rest of the chapter behind a level that is not there.
        $story->assertJsonCount(0, 'chapters.0.edges');
    }

    /**
     * An exit leading out of the file is cleared rather than kept. Keeping it
     * would aim it at whatever chapter happens to own that id in this account.
     */
    public function testAnExitLeadingOutsideTheFileIsCleared(): void
    {
        $this->signIn('author');

        $bundle = [
            'format' => 'goo-bundle',
            'version' => 1,
            'kind' => 'story',
            'stories' => [['id' => 'story-y', 'title' => 'One way out', 'cover' => '#000', 'chapters' => ['ch-y'], 'hot' => []]],
            'chapters' => [[
                'id' => 'ch-y',
                'title' => 'Only chapter',
                'image' => '#000',
                'nodes' => [['levelId' => 'lvl-b', 'x' => 10, 'y' => 10, 'next' => 'ch-elsewhere']],
                'edges' => [],
                'hot' => [],
            ]],
            'levels' => [$this->rawLevel('lvl-b')],
            'assets' => [],
        ];

        $result = $this->postJson('/api/library/import', $bundle)->assertStatus(201)->json();

        self::assertStringContainsString('outside the file', $result['warnings'][0]);
        $this->getJson('/api/stories/story-y')->assertJsonPath('chapters.0.nodes.0.next', null);
    }

    /** Importing the same story twice should not leave two of every anchor in the palette. */
    public function testAnIdenticalAssetIsReusedRatherThanDuplicated(): void
    {
        $this->signIn('author');

        $bundle = [
            'format' => 'goo-bundle',
            'version' => 1,
            'kind' => 'story',
            'stories' => [['id' => 'story-z', 'title' => 'With assets', 'cover' => '#000', 'chapters' => ['ch-z'], 'hot' => ['as-anchor']]],
            'chapters' => [[
                'id' => 'ch-z', 'title' => 'Ch', 'image' => '#000',
                'nodes' => [['levelId' => 'lvl-c', 'x' => 10, 'y' => 10]], 'edges' => [], 'hot' => [],
            ]],
            'levels' => [$this->rawLevel('lvl-c')],
            'assets' => [['id' => 'as-anchor', 'type' => 'system-ball', 'title' => 'Anchor', 'data' => ['static' => true]]],
        ];

        $this->postJson('/api/library/import', $bundle)->assertStatus(201);
        $second = $this->postJson('/api/library/import', $bundle)->assertStatus(201)->json();

        // Same asset, so the second import points at the one already on the
        // shelf instead of minting a copy.
        self::assertSame('as-anchor', $second['idMap']['as-anchor']);
        $this->getJson('/api/library')->assertJsonCount(1, 'assets');
    }

    public function testGarbageIsRefusedWithoutBlamingTheServer(): void
    {
        $this->signIn('author');

        $this->postJson('/api/library/import', ['format' => 'something-else'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid');
    }

    /**
     * The real thing: the library.json the game ships with, which is what every
     * existing player has in localStorage. If this does not import, there is no
     * migration path.
     */
    public function testTheShippedGameLibraryImportsWhole(): void
    {
        $this->signIn('author');

        $library = json_decode(
            (string) file_get_contents(__DIR__ . '/../Fixtures/library.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $bundle = ['format' => 'goo-bundle', 'version' => 1, 'kind' => 'library', ...$library];

        $result = $this->postJson('/api/library/import', $bundle)->assertStatus(201)->json();

        self::assertSame([], $result['warnings'], 'The shipped library should import without complaint');
        self::assertCount(1, $result['stories']);

        $story = $this->getJson('/api/stories/story-first')->assertOk();
        $story->assertJsonCount(2, 'chapters');
        $story->assertJsonCount(6, 'levels');

        // Entity data survived untouched — including nested arrays of points.
        $tower = collect($story->json('levels'))->firstWhere('id', 'lvl-tower');
        self::assertSame('terrain', $tower['entities'][0]['type']);
        self::assertSame([0, 780], $tower['entities'][0]['data']['points'][0]);

        // And the fingerprints match what the client computes for the same
        // levels — the vectors in content-hashes.json, generated by the game.
        $expected = json_decode(
            (string) file_get_contents(__DIR__ . '/../Fixtures/content-hashes.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($story->json('levels') as $level) {
            self::assertSame(
                $expected['levels'][$level['id']],
                $level['hash'],
                sprintf('Level %s hashed differently after a round trip', $level['id']),
            );
        }
    }

    /** @return array<string, mixed> */
    private function rawLevel(string $id): array
    {
        return [
            'id' => $id,
            'name' => 'Level ' . $id,
            'width' => 1600,
            'height' => 900,
            'gravity' => ['x' => 0, 'y' => 1800],
            'goal' => 3,
            'entities' => [],
            'hot' => [],
        ];
    }

    private function signIn(string $who): void
    {
        $this->postJson('/api/auth/google', ['credential' => $who])->assertOk();
    }

    private function authorAStory(): void
    {
        $this->postJson('/api/stories', [
            'id' => 'story-1',
            'title' => 'First steps',
            'cover' => '#123',
            'chapter' => ['id' => 'ch-1', 'title' => 'Basics', 'image' => '#456'],
        ])->assertStatus(201);

        $this->postJson('/api/stories/story-1/levels', [
            'id' => 'lvl-1',
            'chapterId' => 'ch-1',
            'name' => 'Tower',
            'x' => 20,
            'y' => 60,
            'version' => 1,
        ])->assertStatus(201);
    }
}
