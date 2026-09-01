<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Service;

use Wob\Publishing\Domain\ValueObject\ContentSnapshot;

/**
 * Whether a release has earned the canon crown.
 *
 * Every number here is a product decision rather than a technical one, which is
 * why they live in one readable class instead of scattered across queries. They
 * will be argued about and changed; when that happens it should be one file and
 * one test, not a hunt through SQL.
 *
 * The three rules, and what each is actually for:
 *
 *  - QUORUM (150 players who finished ~their route~): the crowd has to be big
 *    enough that a handful of friends cannot crown a story. It counts players
 *    who got 90% of the way through THEIR OWN path, not votes and not
 *    playthroughs, because one determined person can produce many of either.
 *
 *  - AVERAGE (8.0 out of 10): a high bar on purpose. Canon is meant to mean
 *    "as good as the built-in stories", not "better than average".
 *
 *  - SHAPE (3 chapters of 3 levels): a floor on how much story there has to be.
 *    Without it, a single brilliant level could be crowned as though it were a
 *    campaign, and 150 people would find that much easier to reach than the
 *    authors of real stories ever will.
 */
final class CanonPolicy
{
    public const QUORUM = 150;
    public const REQUIRED_AVERAGE = 8.0;
    public const MIN_CHAPTERS = 3;
    public const MIN_LEVELS_PER_CHAPTER = 3;

    /**
     * @param int   $playersAtNinetyPercent how many distinct players cleared 90% of their own route
     * @param float $averageRating          mean of every rating cast on this release's levels
     */
    public function qualifies(
        ContentSnapshot $content,
        int $playersAtNinetyPercent,
        float $averageRating,
    ): bool {
        return $this->hasEnoughStory($content)
            && $playersAtNinetyPercent >= self::QUORUM
            && $averageRating >= self::REQUIRED_AVERAGE;
    }

    /**
     * Shape is checked against the release's own snapshot rather than the live
     * story: a story that has since grown does not retroactively qualify a
     * release that was thin when it was cut.
     */
    public function hasEnoughStory(ContentSnapshot $content): bool
    {
        if (count($content->chapters) < self::MIN_CHAPTERS) {
            return false;
        }

        foreach ($content->chapters as $chapter) {
            $nodes = $chapter->nodes ?? [];

            if (count($nodes) < self::MIN_LEVELS_PER_CHAPTER) {
                return false;
            }
        }

        return true;
    }

    /**
     * What is still missing, for a screen that wants to tell an author why
     * their story has not been crowned. Silence is the worst answer here: an
     * author who cannot see the gap assumes the system is broken or rigged.
     *
     * @return list<string>
     */
    public function unmetRequirements(
        ContentSnapshot $content,
        int $playersAtNinetyPercent,
        float $averageRating,
    ): array {
        $missing = [];

        if (count($content->chapters) < self::MIN_CHAPTERS) {
            $missing[] = sprintf('needs at least %d chapters', self::MIN_CHAPTERS);
        } elseif (!$this->hasEnoughStory($content)) {
            $missing[] = sprintf('every chapter needs at least %d levels', self::MIN_LEVELS_PER_CHAPTER);
        }

        if ($playersAtNinetyPercent < self::QUORUM) {
            $missing[] = sprintf(
                '%d of %d players have finished it',
                $playersAtNinetyPercent,
                self::QUORUM,
            );
        }

        if ($averageRating < self::REQUIRED_AVERAGE) {
            $missing[] = sprintf(
                'rated %.1f, needs %.1f',
                $averageRating,
                self::REQUIRED_AVERAGE,
            );
        }

        return $missing;
    }
}
