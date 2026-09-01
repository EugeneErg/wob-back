<?php

declare(strict_types=1);

namespace Wob\Publishing\Infrastructure\Similarity;

use Wob\Library\Domain\Service\ContentHasher;
use Wob\Publishing\Domain\Service\LevelSimilarity;
use Wob\Publishing\Domain\ValueObject\Similarity;

/**
 * Edit distance between the canonical serialisations of two level versions.
 *
 * The canonical form — not the raw stored JSON — is what gets compared, for
 * the same reason it is what gets hashed: two functionally identical levels
 * can arrive with their keys in a different order or their whitespace
 * different, and a raw-text diff would call that a large change. Reusing
 * ContentHasher::canonicalise() means this measures the same notion of
 * "the content" that the content hash already commits to, rather than
 * inventing a second, slightly different one.
 *
 * PHP's built-in levenshtein() caps out at 255 characters — hopeless for a
 * level with any real number of entities — so distance is computed here at
 * word granularity (split on the delimiters the canonical form actually uses:
 * `{`, `}`, `[`, `]`, `:`, `,`) rather than character by character. A moved
 * coordinate then costs one substitution instead of several, which is both
 * cheaper to compute and closer to what "one thing changed" should mean.
 */
final class LevenshteinLevelSimilarity implements LevelSimilarity
{
    public function __construct(private readonly ContentHasher $hasher)
    {
    }

    public function between(object $before, object $after): Similarity
    {
        $entities = $this->entityDistance(
            $this->byId($before->entities ?? []),
            $this->byId($after->entities ?? []),
        );
        $envelope = $this->distanceRatio(
            $this->tokens($this->envelope($before)),
            $this->tokens($this->envelope($after)),
        );

        // Combined as "either of them changed" rather than as a weighted
        // average, and the boundary is the reason. A weighted average lets an
        // untouched envelope discount a rebuilt level: same size, same goal, so
        // a level with every single entity replaced still measured 15% intact
        // and kept 15% of its votes. Under this form a total rebuild is a total
        // change no matter what the envelope says, while a lone goal or size
        // edit still registers on its own.
        return new Similarity(min(1.0, 1.0 - (1.0 - $entities) * (1.0 - $envelope)));
    }

    /**
     * How far apart two sets of entities are, matched up by id.
     *
     * Comparing the two levels as one flat stream of tokens was the first
     * attempt and it was quietly, badly wrong: every level shares the same
     * vocabulary — `id`, `type`, `data`, `points`, `fill` — and those tokens
     * align perfectly no matter what the level actually contains. Two levels
     * with nothing whatsoever in common measured 35% apart, so a level rebuilt
     * from scratch kept two thirds of its votes. The more entities a level had,
     * the more shared vocabulary there was to pad the resemblance.
     *
     * Matching by id fixes the unit of comparison. An entity that exists on one
     * side only is a whole entity's worth of change; an entity present on both
     * is compared against itself, where shared keys are supposed to match
     * because it IS the same object. The result is the share of the level that
     * changed, which is what the vote carry-over is asking about.
     *
     * @param array<string, object> $before
     * @param array<string, object> $after
     */
    private function entityDistance(array $before, array $after): float
    {
        $ids = array_unique([...array_keys($before), ...array_keys($after)]);

        if ($ids === []) {
            return 0.0;
        }

        $total = 0.0;
        $unmatchedBefore = [];
        $unmatchedAfter = [];

        foreach ($ids as $id) {
            $left = $before[$id] ?? null;
            $right = $after[$id] ?? null;

            if ($left === null) {
                $unmatchedAfter[$id] = $right;

                continue;
            }

            if ($right === null) {
                $unmatchedBefore[$id] = $left;

                continue;
            }

            $total += $this->distanceRatio($this->tokens($left), $this->tokens($right));
        }

        // Whatever is left over gets a second chance, paired by what it
        // contains rather than by what it is called.
        //
        // Matching on ids alone reads an author deleting a rock and redrawing
        // it in the same place as a wholesale rewrite: identical geometry, new
        // ids, and every vote on the level thrown away. Ids are the strong
        // signal and stay the first pass, but they are not the only evidence
        // that two entities are the same thing.
        [$paired, $leftOver] = $this->pairByContent($unmatchedBefore, $unmatchedAfter);
        $total += $paired + $leftOver;

        return $total / count($ids);
    }

    /**
     * Greedily pair entities that lost their ids, by how alike they are.
     *
     * Greedy rather than optimal on purpose. Finding the best possible pairing
     * is an assignment problem, and the difference between the best pairing and
     * a good one is far below the precision this number is ever read at — it
     * decides how many votes carry over, not anything anyone measures.
     *
     * @param array<string, object> $before
     * @param array<string, object> $after
     *
     * @return array{float, float} distance from paired entities, and from those left unpaired
     */
    private function pairByContent(array $before, array $after): array
    {
        // Two entities are only "the same one renamed" if they are genuinely
        // alike. Above this they are treated as one thing removed and another
        // added, which is what they are.
        $threshold = 0.35;

        // All-against-all on a level where every id changed would be a lot of
        // comparisons. The cap is generous enough that no hand-built level
        // reaches it, and it exists only so a pathological input cannot make
        // this quadratic in something unbounded.
        $cap = 120;

        if ($before === [] || $after === [] || count($before) > $cap || count($after) > $cap) {
            return [0.0, (float) (count($before) + count($after))];
        }

        // Tokenising is the expensive part — it canonicalises the entity, which
        // means encoding it — and the first version of this called it inside
        // the inner loop, so every candidate re-tokenised both sides.
        $leftTokens = array_map($this->tokens(...), $before);
        $rightTokens = array_map($this->tokens(...), $after);

        // A cheap fingerprint per entity: how many of each token it holds.
        // Comparing two of these is linear, where the edit distance between
        // them is quadratic — and on a level with a hundred entities the
        // all-against-all edit distance ran to a hundred million operations and
        // took four seconds. Publishing runs one of these per level, so that
        // was minutes for a decent-sized story.
        //
        // So overlap picks the candidate and the edit distance only judges the
        // one it picked: a hundred comparisons instead of ten thousand.
        $leftCounts = array_map($this->counts(...), $leftTokens);
        $rightCounts = array_map($this->counts(...), $rightTokens);

        $distance = 0.0;
        $remaining = $after;

        foreach ($before as $leftId => $left) {
            $bestId = null;
            $bestOverlap = 1.0;

            foreach ($remaining as $id => $right) {
                // Different types are never the same entity under a new id. A
                // terrain and a motor can look structurally alike — same data
                // shape, same field names — and pairing them made two unrelated
                // levels measure 20% apart when they had nothing in common.
                if (($left->type ?? null) !== ($right->type ?? null)) {
                    continue;
                }

                $overlap = $this->overlapDistance($leftCounts[$leftId], $rightCounts[$id]);

                if ($overlap < $bestOverlap) {
                    $bestOverlap = $overlap;
                    $bestId = $id;

                    if ($overlap === 0.0) {
                        break;   // nothing will beat holding exactly the same tokens
                    }
                }
            }

            if ($bestId === null || $bestOverlap > $threshold) {
                $distance += 1.0;   // nothing like it on the other side

                continue;
            }

            // Only now, and only for the one candidate that survived.
            $exact = $this->distanceRatio($leftTokens[$leftId], $rightTokens[$bestId]);

            if ($exact > $threshold) {
                $distance += 1.0;

                continue;
            }

            $distance += $exact;
            unset($remaining[$bestId]);
        }

        // Anything on the new side nobody claimed is an addition.
        return [$distance, (float) count($remaining)];
    }

    /** @return array<string, object> */
    private function byId(mixed $entities): array
    {
        if (!is_array($entities)) {
            return [];
        }

        $byId = [];

        foreach ($entities as $entity) {
            if (is_object($entity) && isset($entity->id)) {
                $byId[(string) $entity->id] = $entity;
            }
        }

        return $byId;
    }

    /** Everything about a level except what is placed in it. */
    private function envelope(object $level): object
    {
        $envelope = new \stdClass();

        foreach (['id', 'width', 'height', 'gravity', 'goal'] as $field) {
            if (isset($level->$field)) {
                $envelope->$field = $level->$field;
            }
        }

        return $envelope;
    }

    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    private function distanceRatio(array $left, array $right): float
    {
        $longest = max(count($left), count($right));

        if ($longest === 0) {
            return 0.0;
        }

        return min(1.0, $this->editDistance($left, $right) / $longest);
    }

    /**
     * The meaningful pieces of the canonical form — keys, values, numbers —
     * with the JSON skeleton thrown away.
     *
     * Keeping the braces and commas as tokens was the first attempt and it
     * quietly broke the whole measure: two entirely unrelated levels share all
     * of their punctuation, so the skeleton alone made them look about half
     * similar, and a level rebuilt from scratch kept most of its old votes.
     * Structure is not content. What distinguishes one level from another is
     * which entities it holds and where they sit, and those are exactly the
     * tokens left after the delimiters are dropped.
     *
     * @return list<string>
     */
    private function tokens(mixed $level): array
    {
        $canonical = $this->hasher->canonicalise($level);
        $pieces = preg_split('/[{}\[\]:,]+/', $canonical, -1, PREG_SPLIT_NO_EMPTY);

        return $pieces === false ? [$canonical] : array_values($pieces);
    }

    /**
     * How many of each token an entity holds.
     *
     * @param list<string> $tokens
     *
     * @return array<string, int>
     */
    private function counts(array $tokens): array
    {
        $counts = [];

        foreach ($tokens as $token) {
            $counts[$token] = ($counts[$token] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * How far apart two token bags are, ignoring order: one minus the share of
     * tokens they hold in common.
     *
     * A weaker measure than edit distance — it cannot tell a moved point from a
     * changed one — but it is linear rather than quadratic, and it only has to
     * be good enough to shortlist. Whatever it picks is then judged properly.
     *
     * @param array<string, int> $left
     * @param array<string, int> $right
     */
    private function overlapDistance(array $left, array $right): float
    {
        $shared = 0;
        $total = 0;

        foreach ($left as $token => $count) {
            $other = $right[$token] ?? 0;
            $shared += min($count, $other);
            $total += max($count, $other);
        }

        foreach ($right as $token => $count) {
            if (!isset($left[$token])) {
                $total += $count;
            }
        }

        return $total === 0 ? 0.0 : 1.0 - $shared / $total;
    }

    /**
     * Classic Wagner–Fischer, single-row. O(n*m) time, O(min(n,m)) space — a
     * level's canonical form runs to a few thousand tokens at most, so the
     * quadratic cost is a non-issue; the point of the single-row form is only
     * to avoid holding a full matrix for no reason.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    private function editDistance(array $a, array $b): int
    {
        if (count($a) < count($b)) {
            [$a, $b] = [$b, $a];
        }

        $n = count($a);
        $m = count($b);
        $previous = range(0, $m);

        for ($i = 1; $i <= $n; $i++) {
            $current = [$i];

            for ($j = 1; $j <= $m; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $current[$j] = min(
                    $previous[$j] + 1,        // deletion
                    $current[$j - 1] + 1,     // insertion
                    $previous[$j - 1] + $cost, // substitution
                );
            }

            $previous = $current;
        }

        return $previous[$m];
    }
}
