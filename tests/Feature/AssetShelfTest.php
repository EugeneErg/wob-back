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

        // And a stranger cannot reach into it by guessing the id.
        $this->deleteJson("/api/assets/{$mine}")->assertStatus(404);
    }

    public function testAnAssetCanBeRenamedAndRearranged(): void
    {
        $this->signIn('author');
        $made = $this->postJson('/api/assets', [
            'title' => 'First try',
            'entities' => [['id' => 'e-1', 'type' => 'terrain', 'data' => ['x' => 0]]],
        ])->assertStatus(201)->json('id');

        $this->patchJson("/api/assets/{$made}", [
            'title' => 'Second try',
            'entities' => [
                ['id' => 'e-1', 'type' => 'terrain', 'data' => ['x' => 10]],
                ['id' => 'e-2', 'type' => 'sand', 'data' => ['x' => 20]],
            ],
        ])->assertOk()->assertJsonPath('title', 'Second try')->assertJsonPath('types', ['terrain', 'sand']);

        self::assertCount(2, $this->getJson('/api/assets')->json('assets.0.entities'));
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
