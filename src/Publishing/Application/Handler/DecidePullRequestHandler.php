<?php

declare(strict_types=1);

namespace Wob\Publishing\Application\Handler;

use Illuminate\Database\ConnectionInterface;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Application\Command\DecidePullRequest;
use Wob\Publishing\Domain\Model\PullRequest;
use Wob\Publishing\Domain\Repository\PullRequestRepository;
use Wob\Publishing\Domain\Repository\ReleaseRepository;
use Wob\Achievements\Application\Handler\GrantAwards;
use Wob\Publishing\Domain\Service\ForkFactory;
use Wob\Publishing\Domain\ValueObject\PullRequestId;
use Wob\Shared\Domain\Clock;
use Wob\Shared\Domain\Exception\InvariantViolation;
use Wob\Shared\Domain\Exception\NotFound;

/**
 * Accept, reject, or withdraw a proposal.
 *
 * Accepting produces a NEW draft for the author rather than writing into the
 * one they are working in, and that is the whole design of it. An author has a
 * draft open for their own reasons — a chapter half redrawn, an idea being
 * tried — and merging someone else's work into it would interleave two people's
 * unfinished thoughts with no way to tell them apart afterwards. A separate
 * draft is something they can open, play, edit and publish, or abandon, without
 * ever having risked what they already had.
 *
 * Accepting also does not publish. What comes out is a draft, and it becomes
 * playable only when the author cuts a release from it. Accepting a proposal
 * must never be able to change what players are in the middle of playing.
 *
 * Whole or nothing: the server does not understand entity data and so cannot
 * offer a meaningful line-by-line merge. A half-taken proposal would be a
 * combination nobody — neither author nor contributor — ever played.
 */
final readonly class DecidePullRequestHandler
{
    public function __construct(
        private PullRequestRepository $pullRequests,
        private ReleaseRepository $releases,
        private ForkFactory $forks,
        private GrantAwards $awards,
        private Clock $clock,
        private ConnectionInterface $db,
    ) {
    }

    /** @return StoryId|null the new draft, when the proposal was accepted */
    public function __invoke(DecidePullRequest $command): ?StoryId
    {
        $actor = new OwnerId($command->actorId);
        $id = new PullRequestId($command->pullRequestId);

        return $this->db->transaction(function () use ($command, $actor, $id): ?StoryId {
            $pr = $this->pullRequests->find($id) ?? throw NotFound::of('Pull request', $id->value);
            $now = $this->clock->now();

            if ($command->decision === DecidePullRequest::WITHDRAW) {
                $pr->withdrawnBy($actor, $now);
                $this->pullRequests->save($pr);

                return null;
            }

            $owner = $this->storyOwner($pr);

            if ($command->decision === DecidePullRequest::REJECT) {
                $pr->rejectedBy($owner, $actor, $now);
                $this->pullRequests->save($pr);

                return null;
            }

            if ($command->decision !== DecidePullRequest::ACCEPT) {
                throw InvariantViolation::because(sprintf('"%s" is not a decision', $command->decision));
            }

            $pr->acceptedBy($owner, $actor, $now);

            $draft = $this->forks->draftFromAccepted(
                $owner,
                $this->releases->get($pr->baseReleaseId),
                $pr->forkStoryId,
            );

            $this->pullRequests->save($pr);
            $this->awards->afterContributionAccepted($pr->authorId->value, $pr->targetStoryId->value);

            return $draft;
        });
    }

    private function storyOwner(PullRequest $pr): OwnerId
    {
        $owner = $this->db->table('stories')
            ->where('public_id', $pr->targetStoryId->value)
            ->value('owner_id');

        return $owner === null
            ? throw NotFound::of('Story', $pr->targetStoryId->value)
            : new OwnerId((string) $owner);
    }
}
