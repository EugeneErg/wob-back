<?php

declare(strict_types=1);

namespace Wob\Achievements\Application\Handler;

use Illuminate\Database\ConnectionInterface;
use Wob\Achievements\Domain\Model\Achievement;
use Wob\Achievements\Domain\Model\Award;
use Wob\Achievements\Domain\Repository\AwardRepository;
use Wob\Achievements\Domain\Service\AchievementCatalog;
use Wob\Shared\Domain\Clock;

/**
 * Works out what somebody has earned, from what is already recorded.
 *
 * Deliberately a reader of existing facts rather than a listener on a parallel
 * stream of its own. Everything an achievement could be about — levels
 * finished, routes completed, times on a board, a story crowned — is already
 * written down because something else needed it. Adding a second record of the
 * same events would create two truths that drift, and the one nobody looks at
 * would be the one that rots.
 *
 * Every method is safe to call repeatedly. They are invoked from triggers that
 * fire often — finishing a level, submitting a run — and the repository refuses
 * duplicates, so a caller never has to remember what has already been granted.
 *
 * Nothing here can fail a caller. Awards are a garnish on somebody's victory
 * screen; a broken achievement must never take down the thing that earned it.
 */
final readonly class GrantAwards
{
    public function __construct(
        private AwardRepository $awards,
        private AchievementCatalog $catalog,
        private ConnectionInterface $db,
        private Clock $clock,
    ) {
    }

    /** After a level is finished. */
    public function afterLevelFinished(string $playerId): void
    {
        $this->give($playerId, AchievementCatalog::FIRST_LEVEL);
    }

    /**
     * After a route completion is recorded — for the player, and for the author
     * whose audience just grew.
     */
    public function afterRouteProgress(string $playerId, string $releaseId, string $storyPublicId): void
    {
        $completion = $this->db->table('release_completions')
            ->where('release_id', $releaseId)
            ->where('player_id', $playerId)
            ->first();

        if ($completion === null || (int) $completion->levels_on_route === 0) {
            return;
        }

        $fraction = (int) $completion->levels_finished / (int) $completion->levels_on_route;

        if ($fraction >= 0.9) {
            $this->give($playerId, AchievementCatalog::STORY_FINISHED, $storyPublicId);
        }

        if ($fraction >= 1.0) {
            $this->give($playerId, AchievementCatalog::STORY_HUNDRED, $storyPublicId);
        }

        $story = $this->db->table('stories')->where('public_id', $storyPublicId)->first();

        if ($story === null) {
            return;
        }

        if ($fraction >= 0.9 && $story->canonical_release_id !== null) {
            $this->give($playerId, AchievementCatalog::CANON_FINISHED, $storyPublicId);
        }

        $this->audienceFor((string) $story->owner_id, $storyPublicId, $releaseId, $playerId);
    }

    /**
     * The author's audience.
     *
     * Counted in players who actually finished, not in playthroughs started —
     * that is the one number here worth faking, and finishing puts the price of
     * a fake audience at playing the whole story once per account.
     *
     * The author's own completion does not count towards it. Their story
     * needing them to finish it before anyone else may play is a rule from
     * elsewhere, and letting that same act pay them an audience point would
     * hand every author their first tier for free.
     */
    private function audienceFor(string $authorId, string $storyPublicId, string $releaseId, string $playerId): void
    {
        if ($authorId === $playerId) {
            return;
        }

        $finishers = $this->db->table('release_completions')
            ->where('release_id', $releaseId)
            ->where('player_id', '!=', $authorId)
            ->where('levels_on_route', '>', 0)
            ->whereRaw('levels_finished::numeric / levels_on_route >= 0.9')
            ->count();

        foreach ($this->catalog->audienceTiers() as $code => $needed) {
            if ($finishers >= $needed) {
                $this->give($authorId, $code, $storyPublicId);
            }
        }
    }

    /** After a run is submitted: the first one ever, and any placing it earned. */
    public function afterRunSubmitted(string $runnerId, string $releaseId, string $scope, ?string $targetId, string $category): void
    {
        $this->give($runnerId, AchievementCatalog::FIRST_RUN);

        $place = $this->placeOf($runnerId, $releaseId, $scope, $targetId, $category);

        if ($place === null) {
            return;
        }

        // Best tier first, and only one: someone who takes first place should
        // not also collect the podium award for the same board in the same
        // breath. They keep whichever they earned earlier, which is the point
        // of climbing.
        foreach ($this->catalog->placingTiers() as $code => $within) {
            if ($place <= $within) {
                $this->give($runnerId, $code, $this->boardKey($releaseId, $scope, $targetId, $category));

                return;
            }
        }
    }

    /** After a release is published. */
    public function afterRelease(string $authorId, string $storyPublicId): void
    {
        $this->give($authorId, AchievementCatalog::FIRST_RELEASE, $storyPublicId);
    }

    /** After a story is crowned. */
    public function afterCanonised(string $authorId, string $storyPublicId): void
    {
        $this->give($authorId, AchievementCatalog::CANONISED, $storyPublicId);
    }

    /** After somebody's proposal is taken into a story. */
    public function afterContributionAccepted(string $contributorId, string $storyPublicId): void
    {
        $this->give($contributorId, AchievementCatalog::CONTRIBUTION_ACCEPTED, $storyPublicId);
    }

    /**
     * Where this runner stands on one board.
     *
     * Counts how many DISTINCT runners have a better time, so two people tied
     * for the lead are both first rather than first and second.
     */
    private function placeOf(
        string $runnerId,
        string $releaseId,
        string $scope,
        ?string $targetId,
        string $category,
    ): ?int {
        $base = fn () => $this->db->table('speedrun_records')
            ->where('release_id', $releaseId)
            ->where('scope', $scope)
            ->where('category', $category)
            ->when(
                $targetId === null,
                static fn ($q) => $q->whereNull('target_public_id'),
                static fn ($q) => $q->where('target_public_id', $targetId),
            );

        $mine = $base()->where('runner_id', $runnerId)->min('ticks');

        if ($mine === null) {
            return null;
        }

        $ahead = $base()
            ->where('runner_id', '!=', $runnerId)
            ->where('ticks', '<', $mine)
            ->distinct()
            ->count('runner_id');

        return $ahead + 1;
    }

    private function boardKey(string $releaseId, string $scope, ?string $targetId, string $category): string
    {
        // The subject is the board, not the level: a level has a board per
        // category and per release, and placing on one says nothing about the
        // others.
        return substr(sprintf('%s:%s:%s:%s', $releaseId, $scope, $targetId ?? '-', $category), 0, 64);
    }

    private function give(string $userId, string $code, ?string $subjectId = null): void
    {
        $achievement = $this->catalog->get($code);

        $this->awards->grant(new Award(
            $userId,
            $code,
            $achievement->subjectType,
            $achievement->isRepeatable() ? $subjectId : null,
            $achievement->points,
            $this->clock->now(),
        ));
    }
}
