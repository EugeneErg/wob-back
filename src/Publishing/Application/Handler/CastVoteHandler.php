<?php

declare(strict_types=1);

namespace Wob\Publishing\Application\Handler;

use Wob\Publishing\Application\Command\CastVote;
use Wob\Publishing\Domain\Model\Vote;
use Wob\Publishing\Domain\Repository\ReleaseRepository;
use Wob\Publishing\Domain\Repository\VoteRepository;
use Wob\Publishing\Domain\Service\LevelClearance;
use Wob\Publishing\Domain\ValueObject\Rating;
use Wob\Publishing\Domain\ValueObject\ReleaseId;
use Wob\Shared\Domain\Clock;
use Wob\Shared\Domain\Exception\AccessDenied;
use Wob\Shared\Domain\Exception\NotFound;

/**
 * Record one player's opinion of one level.
 *
 * The gate is that you have finished the level you are rating, in this release.
 * It is the cheapest honest defence there is against a rating that means
 * nothing: an opinion about a puzzle from someone who never solved it is not
 * evidence about the puzzle. It also raises the price of a brigade from
 * "make accounts" to "make accounts and actually play", which is the only
 * kind of barrier that scales without moderation.
 */
final readonly class CastVoteHandler
{
    public function __construct(
        private ReleaseRepository $releases,
        private VoteRepository $votes,
        private LevelClearance $clearance,
        private Clock $clock,
    ) {
    }

    public function __invoke(CastVote $command): Vote
    {
        $releaseId = new ReleaseId($command->releaseId);
        $release = $this->releases->get($releaseId);

        if ($release->content->level($command->levelId) === null) {
            throw NotFound::of('Level', $command->levelId);
        }

        if (!$this->clearance->hasCleared($releaseId, $command->levelId, $command->voterId)) {
            throw AccessDenied::of('Vote on a level you have not finished in', $command->levelId);
        }

        $vote = new Vote(
            $releaseId,
            $command->levelId,
            $command->voterId,
            new Rating($command->rating),
            $this->clock->now(),
            carriedOver: false,
            // Full weight, whatever this voter's opinion was worth a moment
            // ago. If their old rating had faded across an edit, rating the
            // level again restores it — they have now played the thing they are
            // rating, which is the only thing weight was ever measuring.
            weight: 1.0,
        );

        // Replaces any earlier opinion of the same player on the same level:
        // people are allowed to change their minds, they are not allowed to
        // vote twice.
        $this->votes->save($vote);

        return $vote;
    }
}
