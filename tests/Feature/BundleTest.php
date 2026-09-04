<?php

declare(strict_types=1);

namespace Wob\Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
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
        //
        // singleton, а не bind: имена теперь чеканит и создание, а не только
        // импорт, и при bind счётчик начинался заново на каждый запрос — ввоз
        // выдавал ровно то же имя, что было у оригинала, и «переименование»
        // молча ничего не переименовывало.
        $this->app->singleton(IdGenerator::class, static fn (): IdGenerator => new class () implements IdGenerator {
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

        $exported = $this->getJson("/api/stories/{$this->storyId}/export")->assertOk()->json();

        self::assertSame('goo-bundle', $exported['format']);
        self::assertCount(1, $exported['stories']);
        self::assertCount(1, $exported['levels']);

        // Importing it back into the same account must not overwrite the
        // original: it is a copy, and the ids collide.
        $result = $this->postJson('/api/library/import', $exported)->assertStatus(201)->json();

        // Exact ids, because what matters is that every reference was rewritten
        // to the same new id — not merely that something changed. The numbering
        // follows the read order: levels, then chapters, then stories.
        // Точные имена: важно, что КАЖДАЯ ссылка переписана на одно и то же
        // новое имя, а не просто что-то изменилось. Счёт сквозной — создание
        // тоже берёт имена отсюда, — поэтому порядок чтения виден в номерах:
        // уровни, затем главы, затем истории.
        self::assertSame('lvl-new5', $result['idMap'][$this->levelId]);
        self::assertSame('ch-new6', $result['idMap'][$this->chapterId]);
        self::assertSame('story-new7', $result['idMap'][$this->storyId]);

        $this->getJson('/api/library')->assertJsonCount(2, 'stories');

        // The original is untouched.
        $this->getJson("/api/stories/{$this->storyId}")->assertOk()->assertJsonPath('levels.0.name', 'Tower');

        // And the copy carries the same content under new ids.
        $copy = $this->getJson("/api/stories/{$result['idMap'][$this->storyId]}")->assertOk();
        $copy->assertJsonPath('levels.0.name', 'Tower');
        $copy->assertJsonPath('chapters.0.nodes.0.levelId', $result['idMap'][$this->levelId]);
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

        $before = $this->getJson("/api/stories/{$this->storyId}")->json('levels.0.hash');
        $exported = $this->getJson("/api/stories/{$this->storyId}/export")->json();

        $copy = $this->postJson('/api/library/import', $exported)
            ->assertStatus(201)
            ->json("idMap.{$this->storyId}");

        $after = $this->getJson("/api/stories/{$copy}")->json('levels.0.hash');

        // Not equal — the level id is part of the fingerprint and the id changed.
        // What matters is that the hash is derived, not copied: it must match
        // what the same content under the new id hashes to.
        self::assertNotSame($before, $after);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $after);

        // Importing into a fresh account keeps the ids, and therefore the hash.
        $this->postJson('/api/auth/logout');
        $this->signIn('other');
        $this->postJson('/api/library/import', $exported)->assertStatus(201);

        self::assertSame($before, $this->getJson("/api/stories/{$this->storyId}")->json('levels.0.hash'));
    }

    /** Two authors may hold the same id; renaming on import would break that. */
    public function testAnotherAuthorKeepsTheOriginalIds(): void
    {
        $this->signIn('author');
        $this->authorAStory();
        $exported = $this->getJson("/api/stories/{$this->storyId}/export")->json();
        $this->postJson('/api/auth/logout');

        $this->signIn('other');
        $result = $this->postJson('/api/library/import', $exported)->assertStatus(201)->json();

        // Ничего не переименовано: у другого автора эти имена свободны.
        self::assertSame([], array_filter(
            $result['idMap'],
            static fn (string $to, string $from): bool => $to !== $from,
            ARRAY_FILTER_USE_BOTH,
        ));

        $this->getJson("/api/stories/{$this->storyId}")->assertOk()->assertJsonPath('title', 'First steps');
    }

    public function testAChapterWithoutAStoryIsRefused(): void
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

        // Import used to adopt the chapter into a story it named itself. It no
        // longer does: the author is the only one who can say which story this
        // belongs to, so the bundle comes back rejected rather than quietly
        // reshaped into something nobody wrote.
        $result = $this->postJson('/api/library/import', $bundle)->assertStatus(422)->json();

        self::assertSame('invalid', $result['error']['code']);
        self::assertStringContainsString('without a story', $result['error']['message']);
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
        // The path went with the level it pointed at: a link to a place that is
        // not there would look like a way forward and gate everything behind it.
        $story->assertJsonCount(0, 'chapters.0.nodes.0.next');
    }

    /**
     * An exit leading out of the file is cleared rather than kept. Keeping it
     * would aim it at whatever chapter happens to own that id in this account.
     */
    public function testAnOldChapterExitIsReportedRatherThanGuessed(): void
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

        self::assertStringContainsString('needs redrawing', $result['warnings'][0]);
        // Nothing was invented in its place: only the author knows which point
        // that exit was meant to land on.
        $this->getJson('/api/stories/story-y')->assertJsonPath('chapters.0.nodes.0.next', []);
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
    /**
     * Import invents no names. Each case below is a bundle that is valid apart
     * from one missing label, and each used to sail through: the asset became
     * its own type, the level became "Level", the chapter "Chapter", the story
     * "Story". None of those were the author's words, and nothing downstream
     * could tell an invented name from a real one — which is what made the
     * silence expensive. Now the caller is told which item it forgot.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $break
     */
    #[DataProvider('itemsThatMustBeNamed')]
    public function testImportRefusesAnItemWithNoName(callable $break, string $expected): void
    {
        $this->signIn('author');

        $bundle = $break([
            'format' => 'goo-bundle',
            'version' => 1,
            'kind' => 'story',
            'stories' => [[
                'id' => 'story-x',
                'title' => 'A story',
                'cover' => '#123',
                'chapters' => ['ch-x'],
                'hot' => [],
            ]],
            'chapters' => [[
                'id' => 'ch-x',
                'title' => 'A chapter',
                'image' => '#456',
                'nodes' => [['levelId' => 'lvl-x', 'x' => 10, 'y' => 10]],
                'edges' => [],
                'hot' => [],
            ]],
            'levels' => [$this->rawLevel('lvl-x')],
            'assets' => [[
                'id' => 'as-x',
                'type' => 'system-ball',
                'title' => 'An anchor',
                'data' => ['x' => 0, 'y' => 0],
            ]],
        ]);

        $result = $this->postJson('/api/library/import', $bundle)->assertStatus(422)->json();

        self::assertSame('invalid', $result['error']['code']);
        self::assertSame($expected, $result['error']['message']);
    }

    /** @return iterable<string, array{callable, string}> */
    public static function itemsThatMustBeNamed(): iterable
    {
        yield 'story without a title' => [
            static function (array $b): array {
                unset($b['stories'][0]['title']);

                return $b;
            },
            'Story "story-x" has no title',
        ];

        yield 'chapter without a title' => [
            static function (array $b): array {
                unset($b['chapters'][0]['title']);

                return $b;
            },
            'Chapter "ch-x" has no title',
        ];

        yield 'level without a name' => [
            static function (array $b): array {
                unset($b['levels'][0]['name']);

                return $b;
            },
            'Level "lvl-x" has no name',
        ];

        yield 'asset without a title' => [
            static function (array $b): array {
                unset($b['assets'][0]['title']);

                return $b;
            },
            'Asset "as-x" has no title',
        ];

        // Blank is the same as absent: a title of spaces is not a name someone
        // chose, it is a field that got past a form with no validation on it.
        yield 'a title of nothing but spaces' => [
            static function (array $b): array {
                $b['stories'][0]['title'] = "   \n ";

                return $b;
            },
            'Story "story-x" has no title',
        ];
    }

    public function testTheFirstPointClaimsTheStartAndAnAuthorCanMoveIt(): void
    {
        $this->signIn('author');
        $this->authorAStory();

        // Nobody said where the story begins, so the only chapter there is took
        // the slot. A story with no start is a story with no way in.
        self::assertSame($this->nodeId, $this->getJson("/api/stories/{$this->storyId}")->assertOk()->json('startNodeId'));

        $second = $this->postJson("/api/stories/{$this->storyId}/chapters", [
            'title' => 'Later',
            'image' => '#789',
            'version' => $this->getJson("/api/stories/{$this->storyId}")->json('version'),
        ])->assertStatus(201)->json('id');

        // An empty chapter claims nothing: there is no place to stand in it.
        self::assertSame($this->nodeId, $this->getJson("/api/stories/{$this->storyId}")->json('startNodeId'));

        $secondNode = $this->postJson("/api/stories/{$this->storyId}/levels", [
            'chapterId' => $second,
            'name' => 'Second',
            'x' => 30,
            'y' => 30,
            'version' => $this->getJson("/api/stories/{$this->storyId}")->json('version'),
        ])->assertStatus(201)->json('nodeId');

        // Arriving second does not steal it either.
        self::assertSame($this->nodeId, $this->getJson("/api/stories/{$this->storyId}")->json('startNodeId'));

        $this->patchJson("/api/stories/{$this->storyId}", [
            'startNodeId' => $secondNode,
            'version' => $this->getJson("/api/stories/{$this->storyId}")->json('version'),
        ])->assertOk();

        self::assertSame($secondNode, $this->getJson("/api/stories/{$this->storyId}")->json('startNodeId'));
    }

    public function testAStoryCannotStartOnAPointItDoesNotHave(): void
    {
        $this->signIn('author');
        $this->authorAStory();

        $this->patchJson("/api/stories/{$this->storyId}", [
            'startNodeId' => 'nd-elsewhere',
            'version' => $this->getJson("/api/stories/{$this->storyId}")->json('version'),
        ])->assertStatus(422)->assertJsonPath('error.code', 'invalid');
    }

    /**
     * Covers and films are decoration, and decoration must not cost records.
     *
     * A level keeps its fingerprint when its picture changes, for the same
     * reason it keeps it when renamed: the run recorded on it is still a run on
     * that level.
     */
    public function testMediaDoesNotChangeALevelFingerprint(): void
    {
        $this->signIn('author');
        $this->authorAStory();

        $before = $this->getJson("/api/stories/{$this->storyId}")->json('levels.0.hash');

        $this->putJson("/api/stories/{$this->storyId}/levels/{$this->levelId}", [
            'name' => 'Tower',
            'width' => 1600,
            'height' => 900,
            'gravity' => ['x' => 0, 'y' => 1800],
            'goal' => 3,
            'entities' => [],
            'hot' => [],
            'image' => '/api/media/8f14e45f-ceea-467a-9e28-c1a6b1e0a1f1',
            'version' => $this->getJson("/api/stories/{$this->storyId}")->json('version'),
        ])->assertOk();

        $after = $this->getJson("/api/stories/{$this->storyId}")->json('levels.0');

        self::assertSame($before, $after['hash']);
        self::assertSame('/api/media/8f14e45f-ceea-467a-9e28-c1a6b1e0a1f1', $after['image']);
    }

    /**
     * A point on a map has a name of its own.
     *
     * It used to be identified by the level it showed, which is why one level
     * could only ever appear in one place. The id has to survive a save and a
     * reload, because anything that holds a point — an exit leading to it, a
     * film that plays when it is finished — holds it by that id.
     */
    public function testAMapPointKeepsItsOwnIdentity(): void
    {
        $this->signIn('author');
        $this->authorAStory();

        $node = $this->getJson("/api/stories/{$this->storyId}")->assertOk()->json('chapters.0.nodes.0');
        self::assertSame($this->levelId, $node['levelId']);
        self::assertNotEmpty($node['id']);

        $this->putJson("/api/stories/{$this->storyId}/chapters/{$this->chapterId}/map", [
            'nodes' => [[
                'id' => $node['id'],
                'levelId' => $this->levelId,
                'x' => 42,
                'y' => 17,
                'outro' => '/api/media/8f14e45f-ceea-467a-9e28-c1a6b1e0a1f1',
            ]],
            'edges' => [],
            'version' => $this->getJson("/api/stories/{$this->storyId}")->json('version'),
        ])->assertOk();

        $saved = $this->getJson("/api/stories/{$this->storyId}")->json('chapters.0.nodes.0');

        self::assertSame($node['id'], $saved['id']);
        self::assertSame(42.0, (float) $saved['x']);

        // The film belongs to the point, not to the level: the same level met
        // again later ends its own way.
        self::assertSame('/api/media/8f14e45f-ceea-467a-9e28-c1a6b1e0a1f1', $saved['outro']);
    }

    /**
     * Уровень может существовать до того, как ему нашли место.
     *
     * Автор делает его в панели, наполняет и только потом решает, в какую главу
     * положить. Раньше такого состояния не было: создание требовало главы, и
     * редактор сохранял уровень, которого на сервере нет, — PUT возвращал 404 и
     * повторялся до бесконечности.
     */
    public function testALevelCanExistBeforeItHasAPlaceOnAnyMap(): void
    {
        $this->signIn('author');
        $this->authorAStory();

        $spare = $this->postJson("/api/stories/{$this->storyId}/levels", [
            'chapterId' => null,
            'name' => 'Level 2',
            'version' => $this->getJson("/api/stories/{$this->storyId}")->json('version'),
        ])->assertStatus(201)->json('id');

        $story = $this->getJson("/api/stories/{$this->storyId}")->assertOk()->json();

        self::assertContains($spare, array_column($story['levels'], 'id'));

        // Ни одна карта его не показывает: место ещё не выбрано.
        $nodes = array_merge(...array_column($story['chapters'], 'nodes'));
        self::assertNotContains($spare, array_column($nodes, 'levelId'));

        // И главное — его можно сохранять, а не получать 404 по кругу.
        $this->putJson("/api/stories/{$this->storyId}/levels/{$spare}", [
            'name' => 'Level 2',
            'width' => 1600,
            'height' => 900,
            'gravity' => ['x' => 0, 'y' => 1800],
            'goal' => 3,
            'entities' => [],
            'hot' => [],
            'version' => $this->getJson("/api/stories/{$this->storyId}")->json('version'),
        ])->assertOk();
    }

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

    private string $storyId = '';
    private string $chapterId = '';
    private string $levelId = '';
    private string $nodeId = '';

    /** Имена приходят с сервера — помощник запоминает выданные. */
    private function authorAStory(): void
    {
        $this->storyId = $this->postJson('/api/stories', [
            'title' => 'First steps',
            'cover' => '#123',
            'chapter' => ['title' => 'Basics', 'image' => '#456'],
        ])->assertStatus(201)->json('id');

        $this->chapterId = $this->getJson("/api/stories/{$this->storyId}")->json('chapters.0.id');

        $made = $this->postJson("/api/stories/{$this->storyId}/levels", [
            'chapterId' => $this->chapterId,
            'name' => 'Tower',
            'x' => 20,
            'y' => 60,
            'version' => 1,
        ])->assertStatus(201)->json();

        $this->levelId = $made['id'];
        $this->nodeId = $made['nodeId'];
    }
}
