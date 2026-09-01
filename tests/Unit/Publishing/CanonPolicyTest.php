<?php

declare(strict_types=1);

namespace Wob\Tests\Unit\Publishing;

use PHPUnit\Framework\TestCase;
use stdClass;
use Wob\Publishing\Domain\Service\CanonPolicy;
use Wob\Publishing\Domain\ValueObject\ContentSnapshot;
use Wob\Publishing\Domain\ValueObject\RouteCompletion;

final class CanonPolicyTest extends TestCase
{
    private CanonPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new CanonPolicy();
    }

    public function testAReleaseMeetingEveryBarQualifies(): void
    {
        self::assertTrue($this->policy->qualifies($this->story(3, 3), 150, 8.0));
    }

    /** All three bars are hard: missing any one of them is enough to keep the crown off. */
    public function testEachBarAloneIsEnoughToDisqualify(): void
    {
        self::assertFalse($this->policy->qualifies($this->story(3, 3), 149, 8.0), 'quorum');
        self::assertFalse($this->policy->qualifies($this->story(3, 3), 150, 7.9), 'rating');
        self::assertFalse($this->policy->qualifies($this->story(2, 3), 150, 8.0), 'too few chapters');
        self::assertFalse($this->policy->qualifies($this->story(3, 2), 150, 8.0), 'a chapter too thin');
    }

    /**
     * One thin chapter among fat ones still fails. The floor is about every
     * chapter being a chapter, not about the total adding up.
     */
    public function testOneThinChapterSinksTheWholeStory(): void
    {
        $chapters = [$this->chapter(5), $this->chapter(5), $this->chapter(1)];

        self::assertFalse(
            $this->policy->qualifies(new ContentSnapshot($chapters, []), 500, 9.5),
        );
    }

    /**
     * An author who is not there yet must be able to see the gap. Silence is
     * the worst answer: it reads as the system being broken or rigged.
     */
    public function testItSaysWhatIsStillMissing(): void
    {
        $missing = $this->policy->unmetRequirements($this->story(3, 3), 40, 6.5);

        self::assertCount(2, $missing);
        self::assertStringContainsString('40 of 150', $missing[0]);
        self::assertStringContainsString('6.5', $missing[1]);
    }

    public function testNothingIsMissingOnceItQualifies(): void
    {
        self::assertSame([], $this->policy->unmetRequirements($this->story(3, 3), 150, 8.0));
    }

    /** 90% of a player's own route, so a branching story is not punished for branching. */
    public function testRouteCompletionCountsTheRouteThePlayerActuallyWalked(): void
    {
        self::assertTrue((new RouteCompletion(9, 10))->countsTowardsQuorum());
        self::assertTrue((new RouteCompletion(5, 5))->countsTowardsQuorum());
        self::assertFalse((new RouteCompletion(8, 10))->countsTowardsQuorum());
    }

    private function story(int $chapters, int $levelsEach): ContentSnapshot
    {
        return new ContentSnapshot(
            array_map(fn (): stdClass => $this->chapter($levelsEach), range(1, $chapters)),
            [],
        );
    }

    private function chapter(int $levels): stdClass
    {
        $chapter = new stdClass();
        $chapter->id = 'ch-' . bin2hex(random_bytes(3));
        $chapter->nodes = array_map(
            static fn (int $i): stdClass => (object) ['levelId' => 'lvl-' . $i, 'x' => 10, 'y' => 10],
            range(1, $levels),
        );

        return $chapter;
    }
}
