<?php

declare(strict_types=1);

namespace Wob\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Wob\Identity\Application\DTO\GoogleIdentity;
use Wob\Identity\Application\Exception\AuthenticationFailed;
use Wob\Identity\Application\Port\GoogleIdentityVerifier;
use Wob\Tests\TestCase;

/**
 * The shelf belongs to the author, not to a story.
 *
 * It used to live inside a library bundle, which made it really the client's,
 * with the server keeping a copy from the last upload. An asset survives the
 * story it was made in and the browser it was made on, so it has routes of its
 * own now.
 */
final class AssetShelfTest extends TestCase
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
                    'stranger' => new GoogleIdentity('sub-2', 'stranger@example.com', true, 'Stranger', null),
                    default => throw AuthenticationFailed::because('no'),
                };
            }
        });
    }

    public function testAnAssetCanHoldAWholeArrangement(): void
    {
        $this->signIn('author');

        // A motor with the arm it turns. Saving these one at a time would keep
        // both parts and lose the thing worth keeping: how they are joined.
        $created = $this->postJson('/api/assets', [
            'title' => 'Motor and arm',
            'entities' => [
                ['id' => 'e-motor', 'type' => 'motor', 'data' => ['x' => 0, 'y' => 0]],
                ['id' => 'e-arm', 'type' => 'object', 'data' => ['x' => 40, 'y' => 0], 'parent' => 'e-motor'],
            ],
        ])->assertStatus(201)->json();

        self::assertCount(2, $created['entities']);
        self::assertSame('e-motor', $created['entities'][1]['parent']);

        // A palette groups by type, and a group of several belongs under all of
        // them: someone hunting for the motor should find this.
        self::assertSame(['motor', 'object'], $created['types']);
    }

    public function testASingleEntityIsJustAGroupOfOne(): void
    {
        $this->signIn('author');

        $this->postJson('/api/assets', [
            'title' => 'Anchor',
            'entities' => [['id' => 'e-1', 'type' => 'system-ball', 'data' => ['x' => 0, 'y' => 0]]],
        ])->assertStatus(201)->assertJsonPath('types', ['system-ball']);
    }

    /**
     * Half an arrangement is worse than none.
     *
     * A child whose parent was left out would arrive in a level attached to
     * nothing — and the joining is exactly what the author saved.
     */
    public function testAnAssetCannotKeepAChildWithoutItsParent(): void
    {
        $this->signIn('author');

        $this->postJson('/api/assets', [
            'title' => 'Arm alone',
            'entities' => [
                ['id' => 'e-arm', 'type' => 'object', 'data' => [], 'parent' => 'e-motor'],
            ],
        ])->assertStatus(422)->assertJsonPath('error.code', 'invalid');
    }

    public function testAnEmptyAssetIsRefused(): void
    {
        $this->signIn('author');

        $this->postJson('/api/assets', ['id' => 'as-void', 'title' => 'Nothing', 'entities' => []])
            ->assertStatus(422);
    }

    public function testTheShelfIsPerAuthor(): void
    {
        $this->signIn('author');
        $mine = $this->postJson('/api/assets', [
            'title' => 'Mine',
            'entities' => [['id' => 'e-1', 'type' => 'terrain', 'data' => []]],
        ])->assertStatus(201)->json('id');

        self::assertCount(1, $this->getJson('/api/assets')->assertOk()->json('assets'));

        $this->signIn('stranger');
        self::assertCount(0, $this->getJson('/api/assets')->assertOk()->json('assets'));

        // And a stranger cannot retire it by guessing the id.
        $this->postJson("/api/assets/{$mine}/retire")->assertStatus(404);
    }

    /**
     * An asset cannot be edited or deleted, only retired.
     *
     * A level names the asset it uses rather than copying it, so the reference
     * only holds while the thing behind it does. Editing would silently rewrite
     * every level ever built on it — including other authors' levels, and
     * released ones whose records are tied to their content hash. Deleting
     * would break them outright, and there is no way to find out who was
     * depending on it first.
     *
     * So improving an asset means publishing a new one, and the old one goes
     * out of fashion: gone from the shelf, still there for whoever built on it.
     */
    public function testAnAssetIsRetiredRatherThanEditedOrDeleted(): void
    {
        $this->signIn('author');
        $made = $this->postJson('/api/assets', [
            'title' => 'First try',
            'entities' => [['id' => 'e-1', 'type' => 'terrain', 'data' => ['x' => 0]]],
        ])->assertStatus(201)->json('id');

        // Neither route exists any more: the API answers as it does for any
        // path it has never heard of.
        $this->patchJson("/api/assets/{$made}", ['title' => 'Second try', 'entities' => []])
            ->assertStatus(404);
        $this->deleteJson("/api/assets/{$made}")->assertStatus(404);

        // Improving it means making another one; both stand side by side.
        $this->postJson('/api/assets', [
            'title' => 'Second try',
            'entities' => [
                ['id' => 'e-1', 'type' => 'terrain', 'data' => ['x' => 10]],
                ['id' => 'e-2', 'type' => 'sand', 'data' => ['x' => 20]],
            ],
        ])->assertStatus(201);

        self::assertCount(2, $this->getJson('/api/assets')->assertOk()->json('assets'));

        // Retiring the first takes it off the shelf and leaves the other.
        $this->postJson("/api/assets/{$made}/retire")->assertOk();

        $left = $this->getJson('/api/assets')->assertOk()->json('assets');
        self::assertCount(1, $left);
        self::assertSame('Second try', $left[0]['title']);

        // Retiring twice is not an error: it is a state, not an event.
        $this->postJson("/api/assets/{$made}/retire")->assertOk();
    }

    /**
     * A level may name an asset instead of copying it, and must not name one
     * that is not there.
     *
     * Naming rather than copying is what stops fifty-odd ball types from being
     * duplicated into every ball that uses them. It is sound only because
     * assets never change: resolve the name today or in a year and the level
     * looks the same. What has to be caught is the name that resolves to
     * nothing — not to draw something sensible instead, but because such a
     * level cannot be drawn at all, and finding that out when a player opens it
     * is far worse than refusing the save.
     */
    public function testALevelMayBuildOnAnAssetButNotOnAMissingOne(): void
    {
        $this->signIn('author');

        $asset = $this->postJson('/api/assets', [
            'title' => 'Common ball',
            'entities' => [['id' => 'ball', 'type' => 'game-ball', 'data' => ['r' => 13, 'color' => '#e2704a']]],
        ])->assertStatus(201)->json('id');

        $story = $this->postJson('/api/stories', [
            'title' => 'A story',
            'cover' => '#000',
            'chapter' => ['title' => 'Chapter one', 'image' => '#123'],
        ])->assertStatus(201)->json();
        $storyId = $story['id'];
        $chapterId = $this->getJson("/api/stories/{$storyId}")->json('chapters.0.id');

        $made = $this->postJson("/api/stories/{$storyId}/levels", [
            'chapterId' => $chapterId,
            'name' => 'A level',
            'x' => 30,
            'y' => 50,
            'version' => $story['version'],
        ])->assertStatus(201)->json();
        $levelId = $made['id'];
        $version = $made['version'];

        $save = function (array $entities) use ($storyId, $levelId, &$version) {
            return $this->putJson("/api/stories/{$storyId}/levels/{$levelId}", [
                'name' => 'A level',
                'width' => 1600,
                'height' => 900,
                'gravity' => ['x' => 0, 'y' => 1800],
                'goal' => 1,
                'entities' => $entities,
                'hot' => [],
                'version' => $version,
            ]);
        };

        // Built on the asset, carrying only where it stands.
        $save([[
            'id' => 'b-1', 'type' => 'game-ball',
            'asset' => $asset,
            'data' => ['x' => 100, 'y' => 200],
        ]])->assertOk();

        $version = $this->getJson("/api/stories/{$storyId}")->json('version');

        // And it comes back with the reference intact, not flattened.
        $back = $this->getJson("/api/stories/{$storyId}")->assertOk()->json();
        $placed = $back['levels'][0]['entities'][0];
        self::assertSame($asset, $placed['asset']);
        self::assertSame(['x' => 100, 'y' => 200], $placed['data']);

        // A name that resolves to nothing is refused outright.
        $save([[
            'id' => 'b-2', 'type' => 'game-ball',
            'asset' => 'no-such-asset',
            'data' => ['x' => 0, 'y' => 0],
        ]])->assertStatus(422);
    }

    public function testTheShelfNeedsASession(): void
    {
        $this->getJson('/api/assets')->assertStatus(401);
    }

    private function signIn(string $who): void
    {
        $this->postJson('/api/auth/google', ['credential' => $who])->assertOk();
    }
}
