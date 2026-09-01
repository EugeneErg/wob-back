<?php

declare(strict_types=1);

namespace Wob\Publishing\Application\Handler;

use Illuminate\Database\ConnectionInterface;
use Wob\Publishing\Domain\Repository\ReleaseCompletionRepository;
use Wob\Publishing\Domain\Repository\ReleaseRepository;
use Wob\Publishing\Domain\Repository\SaveSlotRepository;
use Wob\Achievements\Application\Handler\GrantAwards;
use Wob\Publishing\Domain\Service\RouteProgress;
use Wob\Shared\Domain\Clock;

/**
 * Keeps the record of how far each player has got through a release.
 *
 * Run after a level is finished, because that is the only moment the answer can
 * change. Two things follow from it, and neither had anything writing it
 * before:
 *
 *  - the canon quorum, which counts players who cleared 90% of their own route.
 *    Nothing recorded completions, so the count was permanently zero and no
 *    story could ever be crowned however popular;
 *  - the author's own clearance. A release stays invisible to everyone else
 *    until its author has finished it, and nothing was marking that either — so
 *    nothing was ever published to anybody.
 *
 * Both are derived here from what the server already knows, rather than taken
 * from the client. A client that could report its own route completion could
 * crown its story with a hundred throwaway accounts and one request each.
 */
final readonly class RecordRouteProgressHandler
{
    public function __construct(
        private SaveSlotRepository $slots,
        private ReleaseRepository $releases,
        private ReleaseCompletionRepository $completions,
        private RouteProgress $route,
        private GrantAwards $awards,
        private Clock $clock,
        private ConnectionInterface $db,
    ) {
    }

    public function __invoke(string $playerId, string $slotId): void
    {
        $slot = $this->slots->find($slotId, $playerId);

        if ($slot === null) {
            return;
        }

        $releaseId = $slot->releaseId();

        // A run against the author's draft rather than a release has nothing to
        // be evidence about: drafts are not published, voted on, or crowned.
        if ($releaseId === null) {
            return;
        }

        $release = $this->releases->find($releaseId);

        if ($release === null) {
            return;
        }

        $completion = $this->route->of($release->content, $this->slots->completedLevelIds($slotId));
        $this->completions->record($releaseId, $playerId, $completion);

        $this->clearForAuthor($release, $playerId, $completion->countsTowardsQuorum());

        $this->awards->afterLevelFinished($playerId);
        $this->awards->afterRouteProgress($playerId, $releaseId->value, $release->storyId->value);
    }

    /**
     * The author finishing their own release is what opens it to everyone else.
     *
     * A story its creator cannot complete is not ready for strangers, and this
     * is the one gate simple enough to enforce as a rule rather than as a
     * matter of taste.
     */
    private function clearForAuthor(object $release, string $playerId, bool $finished): void
    {
        if (!$finished || $release->isClearedByAuthor()) {
            return;
        }

        $owner = $this->db->table('stories')
            ->where('public_id', $release->storyId->value)
            ->value('owner_id');

        if ((string) $owner !== $playerId) {
            return;
        }

        $release->clearedByAuthor($this->clock->now());
        $this->releases->save($release);
    }
}
