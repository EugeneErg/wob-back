<?php

declare(strict_types=1);

namespace Wob\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Wob\Achievements\Domain\Repository\AwardRepository;
use Wob\Achievements\Domain\Service\AchievementCatalog;
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
use Wob\Library\Domain\ValueObject\NodeId;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Application\Command\PublishRelease;
use Wob\Publishing\Application\Handler\PublishReleaseHandler;
use Wob\Publishing\Domain\Model\Release;
use Wob\Tests\TestCase;

/**
 * Rewards for playing, racing, and making things.
 *
 * Every award here is derived from a fact something else already recorded, so
 * these tests mostly check that the derivation is right — and, more
 * importantly, that it cannot be farmed.
 */
final class AwardsTest extends TestCase
{
    use RefreshDatabase;

    private string $authorId;
    private Release $release;

    protected function setUp(): void
    {
        parent::setUp();

        $this->authorId = $this->makeUser('author@example.com');
        $this->authorStory('story-1');
        $this->actingAs(new SignedInUser($this->authorId));
        $this->release = app(PublishReleaseHandler::class)(new PublishRelease($this->authorId, 'story-1'));
    }

    public function testPublishingEarnsTheAuthorSomething(): void
    {
        self::assertTrue($this->awards()->has($this->authorId, AchievementCatalog::FIRST_RELEASE, 'story-1'));
        self::assertGreaterThan(0, $this->awards()->totalPoints($this->authorId));
    }

    public function testFinishingYourFirstLevelIsRecognised(): void
    {
        $player = $this->player('one@example.com');
        $this->playThrough(levels: 1);

        self::assertTrue($this->awards()->has($player, AchievementCatalog::FIRST_LEVEL));
        self::assertFalse($this->awards()->has($player, AchievementCatalog::STORY_FINISHED, 'story-1'));
    }

    public function testFinishingAStoryIsRecognisedOncePerStory(): void
    {
        $player = $this->player('one@example.com');
        $this->playThrough();

        self::assertTrue($this->awards()->has($player, AchievementCatalog::STORY_FINISHED, 'story-1'));

        // Playing it again in a second slot must not pay twice.
        $this->playThrough();

        self::assertSame(
            1,
            DB::table('awards')
                ->where('user_id', $player)
                ->where('code', AchievementCatalog::STORY_FINISHED)
                ->count(),
        );
    }

    public function testSettingATimeIsRecognisedAndPlacingIsRecognisedSeparately(): void
    {
        // A release is closed to everyone until its author has finished it, so
        // that has to happen before anybody can set a time on it.
        $this->openTheRelease();

        $player = $this->player('runner@example.com');
        $this->submitRun(900);

        self::assertTrue($this->awards()->has($player, AchievementCatalog::FIRST_RUN));

        // Alone on the board, so first place.
        self::assertTrue($this->awards()->has($player, AchievementCatalog::FIRST_PLACE, $this->boardKey()));
    }

    /**
     * Only the best tier lands. Someone taking first place should not collect
     * the podium award for the same board in the same breath — climbing is the
     * point.
     */
    public function testOnlyTheBestPlacingTierIsGranted(): void
    {
        $this->openTheRelease();

        $player = $this->player('runner@example.com');
        $this->submitRun(900);

        $codes = DB::table('awards')->where('user_id', $player)->pluck('code')->all();

        self::assertContains(AchievementCatalog::FIRST_PLACE, $codes);
        self::assertNotContains(AchievementCatalog::TOP_THREE, $codes);
        self::assertNotContains(AchievementCatalog::TOP_TEN, $codes);
    }

    /**
     * The number worth faking. Counting finishers rather than playthroughs
     * started puts the price of an invented audience at actually playing the
     * whole story once per account.
     */
    public function testAudienceCountsFinishersAndNotTheAuthorThemselves(): void
    {
        // The author finishing their own story opens it to others — and must
        // not, by itself, hand them an audience.
        $this->actingAs(new SignedInUser($this->authorId));
        $this->playThrough();

        self::assertFalse($this->awards()->has($this->authorId, AchievementCatalog::AUDIENCE_TEN, 'story-1'));

        for ($i = 0; $i < 10; $i++) {
            $this->player("p{$i}@example.com");
            $this->playThrough();
        }

        self::assertTrue($this->awards()->has($this->authorId, AchievementCatalog::AUDIENCE_TEN, 'story-1'));
        self::assertFalse($this->awards()->has($this->authorId, AchievementCatalog::AUDIENCE_HUNDRED, 'story-1'));
    }

    /** Someone who starts but does not finish is not an audience. */
    public function testHalfAPlaythroughDoesNotCountTowardsAnAudience(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->player("p{$i}@example.com");
            $this->playThrough(levels: 1);
        }

        self::assertFalse($this->awards()->has($this->authorId, AchievementCatalog::AUDIENCE_TEN, 'story-1'));
    }

    public function testTheProfileShowsWhatIsEarnedAndWhatIsLeft(): void
    {
        $this->player('one@example.com');
        $this->playThrough();

        $body = $this->getJson('/api/me/awards')->assertOk()->json();

        self::assertGreaterThan(0, $body['points']);
        self::assertGreaterThan(0, $body['earned']);

        // The unearned ones are listed too: a list of what exists is a reason
        // to play, where a list of what you have is only a trophy cabinet.
        self::assertCount(count(app(AchievementCatalog::class)->all()), $body['achievements']);

        $unearned = array_filter($body['achievements'], static fn (array $a): bool => !$a['earned']);
        self::assertNotEmpty($unearned);
    }

    public function testTheRankingSpansEverybody(): void
    {
        $this->player('one@example.com');
        $this->playThrough();

        $ranking = $this->getJson('/api/ranking')->assertOk()->json('ranking');

        self::assertNotEmpty($ranking);
        self::assertSame(1, $ranking[0]['place']);
        self::assertGreaterThan(0, $ranking[0]['points']);
    }

    /**
     * Points are copied onto the award, not looked up when totalling.
     * Rebalancing an achievement must not rewrite what people earned before it.
     */
    public function testPointsAreFixedAtTheMomentOfEarning(): void
    {
        $player = $this->player('one@example.com');
        $this->playThrough(levels: 1);

        $stored = (int) DB::table('awards')
            ->where('user_id', $player)
            ->where('code', AchievementCatalog::FIRST_LEVEL)
            ->value('points');

        self::assertSame(app(AchievementCatalog::class)->get(AchievementCatalog::FIRST_LEVEL)->points, $stored);
    }

    // --- helpers ---------------------------------------------------------

    private function awards(): AwardRepository
    {
        return app(AwardRepository::class);
    }

    private function player(string $email): string
    {
        $id = $this->makeUser($email);
        $this->actingAs(new SignedInUser($id));

        return $id;
    }

    private function playThrough(int $levels = 3): void
    {
        $slot = $this->postJson('/api/stories/story-1/slots')->assertStatus(201)->json('id');

        foreach (array_slice(['lvl-1', 'lvl-2', 'lvl-3'], 0, $levels) as $level) {
            $this->postJson('/api/progress/complete', [
                'storyId' => 'story-1',
                'levelId' => $level,
                'slotId' => $slot,
            ])->assertOk();
        }
    }

    /** The author finishing their own story is what opens it to everyone else. */
    private function openTheRelease(): void
    {
        $this->actingAs(new SignedInUser($this->authorId));
        $this->playThrough();
        $this->release = app(\Wob\Publishing\Domain\Repository\ReleaseRepository::class)->get($this->release->id);
    }

    private function submitRun(int $ticks): void
    {
        $this->postJson("/api/releases/{$this->release->id->value}/records", [
            'scope' => 'level',
            'target' => 'lvl-1',
            'category' => 'any',
            'ticks' => $ticks,
            'seed' => 1,
            'rulesVersion' => '1',
            'input' => [],
        ])->assertStatus(201);
    }

    private function boardKey(): string
    {
        return substr("{$this->release->id->value}:level:lvl-1:any", 0, 64);
    }

    private function makeUser(string $email): string
    {
        $existing = DB::table('users')->where('email', $email)->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = Uuid::uuid4()->toString();
        DB::table('users')->insert([
            'id' => $id,
            'google_sub' => 'sub-' . md5($email),
            'email' => $email,
            'display_name' => explode('@', $email)[0],
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
            $nodes[] = new MapNode(new NodeId('nd-' . $id->value), $id, 10.0 * $l, 50.0);
        }

        app(StoryRepository::class)->save(new Story(
            new StoryId($storyId),
            new OwnerId($this->authorId),
            'A story',
            '#000',
            [new Chapter(new ChapterId('ch-1'), 'Chapter', '#123', $nodes)],
            $levels,
        ));
    }
}
