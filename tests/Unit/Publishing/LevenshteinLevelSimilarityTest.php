<?php

declare(strict_types=1);

namespace Wob\Tests\Unit\Publishing;

use PHPUnit\Framework\TestCase;
use stdClass;
use Wob\Library\Infrastructure\Hashing\Fnv1aContentHasher;
use Wob\Publishing\Infrastructure\Similarity\LevenshteinLevelSimilarity;

/**
 * The measure underneath the vote carry-over.
 *
 * Worth testing on its own, because when carry-over misbehaves the question is
 * always "is the rule wrong, or is the measure wrong". These pin the measure.
 */
final class LevenshteinLevelSimilarityTest extends TestCase
{
    private LevenshteinLevelSimilarity $similarity;

    protected function setUp(): void
    {
        $this->similarity = new LevenshteinLevelSimilarity(new Fnv1aContentHasher());
    }

    public function testIdenticalLevelsAreZeroApart(): void
    {
        $a = $this->level([$this->terrain('t1', 0)]);
        $b = $this->level([$this->terrain('t1', 0)]);

        self::assertSame(0.0, $this->similarity->between($a, $b)->value);
    }

    /**
     * The property that makes it a fingerprint of content rather than of
     * formatting: the same level with its keys written in a different order is
     * the same level. The canonical form is what gets compared, which is the
     * same thing the content hash commits to.
     */
    public function testKeyOrderDoesNotCount(): void
    {
        $a = new stdClass();
        $a->id = 'lvl-1';
        $a->goal = 3;
        $a->width = 1600;

        $b = new stdClass();
        $b->width = 1600;
        $b->id = 'lvl-1';
        $b->goal = 3;

        self::assertSame(0.0, $this->similarity->between($a, $b)->value);
    }

    public function testOneChangedNumberIsASmallDistance(): void
    {
        $a = $this->level([$this->terrain('t1', 0), $this->terrain('t2', 400), $this->terrain('t3', 800)]);
        $b = $this->level([$this->terrain('t1', 0), $this->terrain('t2', 401), $this->terrain('t3', 800)]);

        $value = $this->similarity->between($a, $b)->value;

        self::assertGreaterThan(0.0, $value);
        self::assertLessThan(0.15, $value);
    }

    public function testAnAddedEntityCountsAsChange(): void
    {
        $a = $this->level([$this->terrain('t1', 0)]);
        $b = $this->level([$this->terrain('t1', 0), $this->terrain('t2', 400)]);

        self::assertGreaterThan(0.0, $this->similarity->between($a, $b)->value);
    }

    /**
     * Nothing in common should read as nothing in common. This is the case the
     * first implementation got wrong: JSON punctuation is shared by every
     * level, and counting it made unrelated levels look half alike.
     */
    public function testUnrelatedLevelsAreFarApart(): void
    {
        $a = $this->level([$this->terrain('aaa', 10), $this->terrain('bbb', 20)]);
        $b = $this->level([$this->motor('zzz', 900), $this->motor('yyy', 950), $this->motor('xxx', 990)]);

        self::assertGreaterThan(0.6, $this->similarity->between($a, $b)->value);
    }

    /**
     * The bug this caught, and the reason the measure is per-entity now.
     *
     * Comparing the two levels as one flat token stream let their shared JSON
     * vocabulary — `id`, `type`, `data`, `points`, `fill` — align perfectly no
     * matter what they contained. Two levels with nothing in common measured
     * 0.354 apart, so a rebuilt level kept two thirds of its votes, and the
     * effect did not shrink with size: the bigger the level, the more shared
     * vocabulary there was to pad the resemblance.
     */
    public function testUnrelatedLevelsAreCompletelyApartAtEverySize(): void
    {
        foreach ([2, 5, 20, 80] as $size) {
            $a = $this->levelOf($size, 'terrain', seed: 1);
            $b = $this->levelOf($size, 'motor', seed: 99);

            self::assertGreaterThan(
                0.95,
                $this->similarity->between($a, $b)->value,
                sprintf('%d entities in common should still be nothing in common', $size),
            );
        }
    }

    /**
     * The other half of the same property: a small edit stays small however
     * big the level is, and gets proportionally smaller as the level grows,
     * because it is a smaller share of it.
     */
    public function testASmallEditStaysSmallAtEverySize(): void
    {
        $previous = 1.0;

        foreach ([4, 20, 80] as $size) {
            $before = $this->levelOf($size, 'terrain', seed: 1);
            $after = $this->levelOf($size, 'terrain', seed: 1);
            $after->entities[0]->data->points[0][1] = 781;

            $value = $this->similarity->between($before, $after)->value;

            self::assertLessThan(0.05, $value, 'nudging one point is never a big change');
            self::assertLessThan($previous, $value, 'the same edit is a smaller share of a bigger level');
            $previous = $value;
        }
    }

    /** An untouched envelope must not discount a level whose contents were all replaced. */
    public function testAnUnchangedEnvelopeDoesNotShieldARebuiltLevel(): void
    {
        $before = $this->levelOf(10, 'terrain', seed: 1);
        $after = $this->levelOf(10, 'motor', seed: 42);

        // Same size, same gravity, same goal — only the contents differ.
        self::assertSame($before->width, $after->width);
        self::assertSame($before->goal, $after->goal);

        self::assertSame(1.0, $this->similarity->between($before, $after)->value);
    }

    /**
     * Reordering is not editing.
     *
     * Entities are matched by id, so where they sit in the array never enters
     * into it. This is the case a straight text diff of the two levels gets
     * badly wrong: edit distance charges a moved block twice, once as a
     * deletion and once as an insertion, so reversing the order of a level's
     * entities would read as a rewrite and cost an author half their votes for
     * changing nothing.
     */
    public function testReorderingEntitiesIsNotAChange(): void
    {
        $forward = $this->levelOf(10, 'terrain', seed: 1);

        $swapped = $this->levelOf(10, 'terrain', seed: 1);
        [$swapped->entities[0], $swapped->entities[9]] = [$swapped->entities[9], $swapped->entities[0]];

        $reversed = $this->levelOf(10, 'terrain', seed: 1);
        $reversed->entities = array_reverse($reversed->entities);

        self::assertSame(0.0, $this->similarity->between($forward, $swapped)->value);
        self::assertSame(0.0, $this->similarity->between($forward, $reversed)->value);
    }

    /**
     * Ids are the strong signal, not the only one.
     *
     * An author who deletes a rock and redraws it in the same place produces
     * identical geometry under a new id. Matching on ids alone read that as a
     * complete rewrite and threw away every vote on the level, so entities left
     * unmatched get a second pass by what they contain.
     */
    public function testRegeneratedIdsWithIdenticalContentAreBarelyAChange(): void
    {
        $before = $this->levelOf(10, 'terrain', seed: 1);
        $after = $this->levelOf(10, 'terrain', seed: 1);

        foreach ($after->entities as $i => $entity) {
            $entity->id = 'regenerated-' . $i;
        }

        self::assertLessThan(0.1, $this->similarity->between($before, $after)->value);
    }

    /** But that second pass must not pair things that merely look alike structurally. */
    public function testEntitiesOfDifferentTypesAreNeverPaired(): void
    {
        // Same data shape, same field names, different type: not the same
        // entity renamed, however similar the JSON looks.
        $before = $this->levelOf(6, 'terrain', seed: 1);
        $after = $this->levelOf(6, 'motor', seed: 1);

        foreach ($after->entities as $i => $entity) {
            $entity->id = 'other-' . $i;
        }

        self::assertSame(1.0, $this->similarity->between($before, $after)->value);
    }

    /**
     * Publishing runs one comparison per level, so a slow one is a slow
     * publish. The worst case is a level whose ids were all regenerated, which
     * is exactly when the content pairing has the most work to do.
     */
    public function testTheWorstCaseStaysFast(): void
    {
        $before = $this->levelOf(120, 'terrain', seed: 1);
        $after = $this->levelOf(120, 'terrain', seed: 2);

        foreach ($after->entities as $i => $entity) {
            $entity->id = 'regenerated-' . $i;
        }

        $started = microtime(true);
        $this->similarity->between($before, $after);
        $elapsed = (microtime(true) - $started) * 1000;

        self::assertLessThan(
            1000,
            $elapsed,
            sprintf('a single level comparison took %.0f ms', $elapsed),
        );
    }

    private function levelOf(int $entities, string $type, int $seed): stdClass
    {
        $level = $this->level([]);
        $list = [];

        for ($i = 0; $i < $entities; $i++) {
            $e = new stdClass();
            $e->id = "e{$seed}_{$i}";
            $e->type = $type;
            $e->data = (object) [
                'points' => [[$seed * 7 + $i * 13, 780], [$seed * 7 + $i * 13 + 100, 700]],
                'fill' => '#' . substr(md5($seed . $i), 0, 6),
            ];
            $list[] = $e;
        }

        $level->entities = $list;

        return $level;
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

    private function terrain(string $id, int $x): stdClass
    {
        $e = new stdClass();
        $e->id = $id;
        $e->type = 'terrain';
        $e->data = (object) ['points' => [[$x, 780], [$x + 100, 780]], 'fill' => '#2a3326'];

        return $e;
    }

    private function motor(string $id, int $x): stdClass
    {
        $e = new stdClass();
        $e->id = $id;
        $e->type = 'motor';
        $e->data = (object) ['torque' => 42, 'anchor' => [$x, 111], 'spin' => 'left'];

        return $e;
    }
}
