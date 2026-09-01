<?php

declare(strict_types=1);

namespace Wob\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Wob\Identity\Infrastructure\Laravel\Auth\SignedInUser;
use Wob\Library\Domain\Model\Chapter;
use Wob\Library\Domain\Model\Level;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\Repository\StoryRepository;
use Wob\Library\Domain\ValueObject\ChapterId;
use Wob\Library\Domain\ValueObject\Dimensions;
use Wob\Library\Domain\ValueObject\EntityPlacement;
use Wob\Library\Domain\ValueObject\Gravity;
use Wob\Library\Domain\ValueObject\LevelId;
use Wob\Library\Domain\ValueObject\MapNode;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Application\Command\PublishRelease;
use Wob\Publishing\Application\Handler\PublishReleaseHandler;
use Wob\Publishing\Domain\Model\SaveSlot;
use Wob\Tests\TestCase;

/**
 * Save slots: several runs through the same story, kept apart.
 *
 * The point of the whole feature is the isolation, so that is what these test
 * hardest. A second playthrough that arrives already finished is not a second
 * playthrough.
 */
final class SaveSlotTest extends TestCase
{
    use RefreshDatabase;

    private string $playerId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->playerId = $this->makeUser('player@example.com');
        $this->authorStory('story-1');
        app(PublishReleaseHandler::class)(new PublishRelease($this->playerId, 'story-1'));
        $this->actingAs(new SignedInUser($this->playerId));
    }

    public function testAStoryStartsWithNoRuns(): void
    {
        $this->getJson('/api/stories/story-1/slots')
            ->assertOk()
            ->assertJsonPath('slots', [])
            ->assertJsonPath('max', SaveSlot::MAX_PER_STORY);
    }

    public function testStartingRunsNumbersThemInOrder(): void
    {
        self::assertSame(1, $this->startSlot()['number']);
        self::assertSame(2, $this->startSlot()['number']);
        self::assertSame(3, $this->startSlot()['number']);

        // Three is the limit: the point is a handful of parallel runs, not
        // unlimited bookkeeping.
        $this->postJson('/api/stories/story-1/slots')->assertStatus(422);
    }

    /** The whole reason slots exist. */
    public function testProgressInOneRunIsInvisibleToAnother(): void
    {
        $first = $this->startSlot();
        $second = $this->startSlot();

        $this->finish($first['id'], 'lvl-1');
        $this->finish($first['id'], 'lvl-2');

        self::assertSame(['lvl-1', 'lvl-2'], $this->completed($first['id']));
        self::assertSame([], $this->completed($second['id']), 'a fresh run starts fresh');
    }

    public function testFinishingTheSameLevelTwiceInOneRunCountsOnce(): void
    {
        $slot = $this->startSlot();

        $this->finish($slot['id'], 'lvl-1');
        $this->finish($slot['id'], 'lvl-1');

        self::assertSame(['lvl-1'], $this->completed($slot['id']));
        self::assertSame(1, DB::table('level_completions')->whereNotNull('slot_id')->count());
    }

    /**
     * The same level finished in two different runs is two rows, not one.
     *
     * This is the case the partial unique index exists for: a single unique
     * over (user, slot, level) would not constrain the slot-less rows at all in
     * Postgres, and a unique over (user, level) would collapse the two runs
     * into one.
     */
    public function testTheSameLevelCanBeFinishedInEveryRun(): void
    {
        $first = $this->startSlot();
        $second = $this->startSlot();

        $this->finish($first['id'], 'lvl-1');
        $this->finish($second['id'], 'lvl-1');

        // Keyed on the public id now: progress describes a level of a release,
        // not a row in the author's draft that they may delete tomorrow.
        self::assertSame(2, DB::table('level_completions')->where('level_public_id', 'lvl-1')->count());
    }

    public function testErasingARunKeepsItsPlaceOnTheShelf(): void
    {
        $slot = $this->startSlot();
        $this->finish($slot['id'], 'lvl-1');

        $this->postJson("/api/slots/{$slot['id']}/erase")->assertOk();

        self::assertSame([], $this->completed($slot['id']));
        $this->getJson('/api/stories/story-1/slots')->assertJsonCount(1, 'slots');
    }

    public function testDeletingARunFreesItsNumber(): void
    {
        $slot = $this->startSlot();
        $this->startSlot();

        $this->deleteJson("/api/slots/{$slot['id']}")->assertStatus(204);

        self::assertSame(1, $this->startSlot()['number'], 'the freed number is reused');
    }

    public function testARunCanBeNamed(): void
    {
        $slot = $this->startSlot();

        $this->patchJson("/api/slots/{$slot['id']}", ['label' => '100% attempt'])
            ->assertOk()
            ->assertJsonPath('label', '100% attempt');
    }

    /** A run is pinned to the version it started on, and moving is a choice. */
    public function testARunRemembersTheVersionItStartedOn(): void
    {
        $slot = $this->startSlot();

        self::assertNotNull($slot['releaseId']);

        $release = DB::table('releases')->where('id', $slot['releaseId'])->first();
        self::assertSame(1, (int) $release->number);
    }

    public function testAnotherPlayersRunIsNotReachable(): void
    {
        $slot = $this->startSlot();

        $this->actingAs(new SignedInUser($this->makeUser('other@example.com')));

        $this->patchJson("/api/slots/{$slot['id']}", ['label' => 'mine now'])->assertStatus(404);
        $this->deleteJson("/api/slots/{$slot['id']}")->assertStatus(404);
    }

    // --- helpers ---------------------------------------------------------

    /** @return array<string, mixed> */
    private function startSlot(): array
    {
        return $this->postJson('/api/stories/story-1/slots')->assertStatus(201)->json();
    }

    private function finish(string $slotId, string $levelId): void
    {
        $this->postJson('/api/progress/complete', [
            'storyId' => 'story-1',
            'levelId' => $levelId,
            'slotId' => $slotId,
        ])->assertOk();
    }

    /** @return list<string> */
    private function completed(string $slotId): array
    {
        $slots = $this->getJson('/api/stories/story-1/slots')->json('slots');

        foreach ($slots as $slot) {
            if ($slot['id'] === $slotId) {
                $done = $slot['completed'];
                sort($done);

                return $done;
            }
        }

        return [];
    }

    private function makeUser(string $email): string
    {
        $id = Uuid::uuid4()->toString();
        DB::table('users')->insert([
            'id' => $id,
            'google_sub' => 'sub-' . md5($email),
            'email' => $email,
            'display_name' => 'Player',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function authorStory(string $storyId): void
    {
        $levels = [];
        $nodes = [];

        for ($l = 1; $l <= 3; $l++) {
            $id = new LevelId("lvl-{$l}");
            $data = new \stdClass();
            $data->points = [[0, 780], [400, 780]];

            $levels[] = new Level(
                $id,
                "Level {$l}",
                new Dimensions(1600, 900),
                new Gravity(0, 1800),
                3,
                [new EntityPlacement("e{$l}", 'terrain', $data)],
            );
            $nodes[] = new MapNode($id, 10.0 * $l, 50.0);
        }

        app(StoryRepository::class)->save(new Story(
            new StoryId($storyId),
            new OwnerId($this->playerId),
            'A story',
            '#000',
            [new Chapter(new ChapterId('ch-1'), 'Chapter', '#123', $nodes)],
            $levels,
        ));
    }
}
