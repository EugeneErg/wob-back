<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Service;

use DateTimeImmutable;
use Wob\Publishing\Domain\Model\Vote;
use Wob\Publishing\Domain\ValueObject\ReleaseId;

/**
 * Carries a level's opinions across an edit, at reduced weight.
 *
 * Every vote travels — none are discarded — and each counts for less in
 * proportion to how much of the level changed. Edit a level by a third and its
 * ratings still describe two thirds of what is there.
 *
 * The first version of this threw away a fraction of the votes instead, chosen
 * by a deterministic sample. It gave the same average and was worse in a way
 * that only shows from the voter's side: your opinion either survived whole or
 * disappeared, decided by a hash of your own id — nothing you could see, and
 * nothing you could do about it. Weighting fades everyone's opinion by the same
 * amount, which is both fairer and easier to explain.
 *
 * And it heals. Weight is not a permanent mark: someone who plays the new
 * version and rates it again is back to full, because they have now actually
 * seen the thing they are rating. Over time a well-played release converges on
 * opinions formed on its own content, without anyone administering anything.
 *
 * Weight compounds across releases, which is the point rather than a side
 * effect: a level edited a little three times running has been edited, and its
 * old ratings should have faded accordingly.
 */
final class VoteCarryOver
{
    public function __construct(private readonly LevelSimilarity $similarity)
    {
    }

    /**
     * @param list<Vote> $previousVotes votes on this level in the release being superseded
     *
     * @return list<Vote> the same opinions, re-stamped onto the new release
     */
    public function apply(
        array $previousVotes,
        object $levelBefore,
        object $levelAfter,
        ReleaseId $newReleaseId,
        DateTimeImmutable $now,
    ): array {
        if ($previousVotes === []) {
            return [];
        }

        $surviving = $this->similarity->between($levelBefore, $levelAfter)->carryOverFraction();

        return array_map(
            static fn (Vote $vote): Vote => new Vote(
                $newReleaseId,
                $vote->levelId,
                $vote->voterId,
                $vote->rating,
                $now,
                carriedOver: true,
                weight: $vote->weight * $surviving,
            ),
            array_values($previousVotes),
        );
    }
}
