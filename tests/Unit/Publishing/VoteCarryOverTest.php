<?php

declare(strict_types=1);

namespace Wob\Tests\Unit\Publishing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use stdClass;
use Wob\Library\Infrastructure\Hashing\Fnv1aContentHasher;
use Wob\Publishing\Domain\Model\Vote;
use Wob\Publishing\Domain\Service\VoteCarryOver;
use Wob\Publishing\Domain\ValueObject\Rating;
use Wob\Publishing\Domain\ValueObject\ReleaseId;
use Wob\Publishing\Infrastructure\Similarity\LevenshteinLevelSimilarity;

/**
 * The rule that decides how much of a level's reputation survives an edit.
 *
 * This is the part of the design most likely to be argued with once it is
 * live, so the tests are written to state the intent rather than to lock in
 * particular numbers: a nudge keeps almost everything, a rewrite keeps almost
 * nothing, and identical content keeps all of it.
 */
final class VoteCarryOverTest extends TestCase
{
    private VoteCarryOver $carryOver;
    private LevenshteinLevelSimilarity $similarity;

    protected function setUp(): void
    {
        $this->similarity = new LevenshteinLevelSimilarity(new Fnv1aContentHasher());
        $this->carryOver = new VoteCarryOver($this->similarity);
    }

    public function testIdenticalContentKeepsEveryVoteAtFullWeight(): void
    {
        $level = $this->level([$this->entity('t1', 0, 780)]);

        $carried = $this->carryOver->apply(
            $this->votes(20),
            $level,
            $this->level([$this->entity('t1', 0, 780)]),
            ReleaseId::next(),
            new DateTimeImmutable(),
        );

        self::assertCount(20, $carried);

        foreach ($carried as $vote) {
            self::assertSame(1.0, $vote->weight);
        }
    }

    /**
     * Nothing is discarded any more — opinions fade instead.
     *
     * The earlier design dropped a share of the votes outright, chosen by a
     * hash of the voter's id. Same average, but from the voter's side their
     * opinion either survived whole or vanished for reasons they could neither
     * see nor influence.
     */
    public function testNoVoteIsEverDiscarded(): void
    {
        $before = $this->level([$this->entity('t1', 0, 780), $this->entity('t2', 400, 780)]);
        $after = $this->level([$this->entity('zz', 91, 12), $this->entity('yy', 55, 640)]);

        $carried = $this->carryOver->apply($this->votes(50), $before, $after, ReleaseId::next(), new DateTimeImmutable());

        self::assertCount(50, $carried, 'every opinion travels, however much the level changed');
    }

    /** Weight compounds: a level edited repeatedly has its old ratings fade further each time. */
    public function testWeightCompoundsAcrossReleases(): void
    {
        $a = $this->level([$this->entity('t1', 0, 780), $this->entity('t2', 400, 780), $this->entity('t3', 800, 780)]);
        $b = $this->level([$this->entity('t1', 0, 900), $this->entity('t2', 400, 780), $this->entity('t3', 800, 780)]);
        $c = $this->level([$this->entity('t1', 0, 900), $this->entity('t2', 400, 500), $this->entity('t3', 800, 780)]);

        $once = $this->carryOver->apply($this->votes(5), $a, $b, ReleaseId::next(), new DateTimeImmutable());
        $twice = $this->carryOver->apply($once, $b, $c, ReleaseId::next(), new DateTimeImmutable());

        self::assertLessThan($once[0]->weight, $twice[0]->weight);
        self::assertGreaterThan(0.0, $twice[0]->weight);
    }

    /**
     * The case the whole mechanism exists for: an author fixes a typo in one
     * coordinate and should not lose the standing of a level 150 people liked.
     */
    public function testANudgeKeepsNearlyAllOfTheWeight(): void
    {
        $before = $this->level([
            $this->entity('t1', 0, 780),
            $this->entity('t2', 400, 780),
            $this->entity('t3', 800, 780),
            $this->entity('t4', 1200, 780),
        ]);
        $after = $this->level([
            $this->entity('t1', 0, 781),
            $this->entity('t2', 400, 780),
            $this->entity('t3', 800, 780),
            $this->entity('t4', 1200, 780),
        ]);

        $carried = $this->carryOver->apply($this->votes(100), $before, $after, ReleaseId::next(), new DateTimeImmutable());

        self::assertGreaterThan(0.9, $carried[0]->weight, 'a one-pixel move should cost almost nothing');
    }

    public function testARewriteLeavesAlmostNoWeight(): void
    {
        $before = $this->level([$this->entity('t1', 0, 780), $this->entity('t2', 400, 780)]);
        $after = $this->level([
            $this->entity('zz', 91, 12),
            $this->entity('yy', 55, 640),
            $this->entity('xx', 733, 210),
            $this->entity('ww', 12, 400),
        ]);

        $carried = $this->carryOver->apply($this->votes(100), $before, $after, ReleaseId::next(), new DateTimeImmutable());

        self::assertLessThan(0.2, $carried[0]->weight, 'a level rebuilt from scratch should not inherit its old reputation');
    }

    /** Carried votes must be marked, or an audit can never tell a real vote from an inherited one. */
    public function testCarriedVotesAreStampedOntoTheNewReleaseAndFlagged(): void
    {
        $newRelease = ReleaseId::next();
        $carried = $this->carryOver->apply(
            $this->votes(10),
            $this->level([$this->entity('t1', 0, 780)]),
            $this->level([$this->entity('t1', 0, 780)]),
            $newRelease,
            new DateTimeImmutable(),
        );

        foreach ($carried as $vote) {
            self::assertTrue($vote->releaseId->equals($newRelease));
            self::assertTrue($vote->carriedOver);
        }
    }

    /** @return list<Vote> */
    private function votes(int $count): array
    {
        $release = ReleaseId::next();
        $votes = [];

        for ($i = 0; $i < $count; $i++) {
            $votes[] = new Vote($release, 'lvl-1', 'voter-' . $i, new Rating(8), new DateTimeImmutable());
        }

        return $votes;
    }

    /** @param list<stdClass> $entities */
    private function level(array $entities): stdClass
    {
        $level = new stdClass();
        $level->id = 'lvl-1';
        $level->width = 1600;
        $level->height = 900;
        $level->gravity = (object) ['x' => 0, 'y' => 1800];
        $level->goal = 3;
        $level->entities = $entities;

        return $level;
    }

    private function entity(string $id, int $x, int $y): stdClass
    {
        $entity = new stdClass();
        $entity->id = $id;
        $entity->type = 'terrain';
        $entity->data = (object) ['points' => [[$x, $y], [$x + 100, $y]]];

        return $entity;
    }
}
