<?php

declare(strict_types=1);

namespace Wob\Publishing\Application\Handler;

use Illuminate\Database\ConnectionInterface;
use Wob\Publishing\Domain\Repository\ReleaseCompletionRepository;
use Wob\Publishing\Domain\Repository\ReleaseRepository;
use Wob\Publishing\Domain\Repository\VoteRepository;
use Wob\Achievements\Application\Handler\GrantAwards;
use Wob\Publishing\Domain\Service\CanonPolicy;
use Wob\Publishing\Domain\ValueObject\ReleaseId;

/**
 * Decide whether a release has earned the canon crown, and move it if so.
 *
 * Run after the things that could change the answer — a vote, a completion —
 * rather than on a schedule, so that an author watching their story cross the
 * line sees it happen rather than finding out an hour later.
 *
 * The crown only ever moves forward. A story that is canonical at version 3
 * stays canonical at version 3 while versions 4 and 5 gather their own votes;
 * if 5 clears the bar, the crown moves to 5. If it never does, the crown never
 * moves, and nothing is ever taken away. That asymmetry is deliberate: an
 * author should never be punished for publishing, and players should never
 * lose the version they were told was the good one because someone edited
 * something.
 */
final readonly class ReevaluateCanonHandler
{
    public function __construct(
        private ReleaseRepository $releases,
        private VoteRepository $votes,
        private ReleaseCompletionRepository $completions,
        private CanonPolicy $policy,
        private GrantAwards $awards,
        private ConnectionInterface $db,
    ) {
    }

    /** @return bool whether this release now holds the crown */
    public function __invoke(ReleaseId $releaseId): bool
    {
        $release = $this->releases->get($releaseId);

        // An unplayed-by-its-own-author release is not a candidate for
        // anything: nobody else can even have played it.
        if (!$release->isClearedByAuthor()) {
            return false;
        }

        $qualifies = $this->policy->qualifies(
            $release->content,
            $this->completions->countAtQuorumThreshold($releaseId),
            $this->votes->averageRating($releaseId),
        );

        if (!$qualifies) {
            return false;
        }

        return $this->db->transaction(function () use ($release, $releaseId): bool {
            $story = $this->db->table('stories')
                ->where('public_id', $release->storyId->value)
                ->lockForUpdate()
                ->first();

            if ($story === null) {
                return false;
            }

            // Only ever forward. A newer release that qualifies replaces an
            // older canonical one; an older release that somehow qualifies
            // later does not unseat a newer crown.
            if ($story->canonical_release_id !== null) {
                $current = $this->releases->find(new ReleaseId($story->canonical_release_id));

                if ($current !== null && $current->number >= $release->number) {
                    return false;
                }
            }

            $this->db->table('stories')
                ->where('id', $story->id)
                ->update([
                    'canonical_release_id' => $releaseId->value,
                    // Stamped only the first time a story is crowned. The
                    // catalogue orders by it, and "the first canonical story"
                    // decides which single level a signed-out visitor may play
                    // — so it has to mean when this story joined the canon, not
                    // when its newest version did.
                    'canonical_since' => $story->canonical_since ?? now(),
                    'updated_at' => now(),
                ]);

            $this->awards->afterCanonised((string) $story->owner_id, $release->storyId->value);

            return true;
        });
    }
}
