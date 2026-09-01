<?php

declare(strict_types=1);

namespace Wob\Publishing\Application\Handler;

use Illuminate\Database\ConnectionInterface;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Application\Command\OpenPullRequest;
use Wob\Publishing\Domain\Model\PullRequest;
use Wob\Publishing\Domain\Repository\ForkOverrideRepository;
use Wob\Publishing\Domain\Repository\PullRequestRepository;
use Wob\Publishing\Domain\Repository\ReleaseRepository;
use Wob\Publishing\Domain\ValueObject\ReleaseId;
use Wob\Shared\Domain\Clock;
use Wob\Shared\Domain\Exception\AccessDenied;
use Wob\Shared\Domain\Exception\InvariantViolation;
use Wob\Shared\Domain\Exception\NotFound;

/**
 * Offer a fork back to the story it came from.
 *
 * The proposal carries no content of its own — it points at the fork, and the
 * fork's overrides ARE the change. Snapshotting the diff here would freeze it
 * at open time, and a contributor polishing their work after review comments
 * would then be silently proposing their old version.
 */
final readonly class OpenPullRequestHandler
{
    public function __construct(
        private PullRequestRepository $pullRequests,
        private ForkOverrideRepository $overrides,
        private ReleaseRepository $releases,
        private Clock $clock,
        private ConnectionInterface $db,
    ) {
    }

    public function __invoke(OpenPullRequest $command): PullRequest
    {
        $forkId = new StoryId($command->forkStoryId);
        $author = new OwnerId($command->authorId);

        $fork = $this->db->table('stories')->where('public_id', $forkId->value)->first()
            ?? throw NotFound::of('Story', $forkId->value);

        if ($fork->owner_id !== $author->value) {
            throw AccessDenied::of('Story', $forkId->value);
        }

        if ($fork->forked_from_release_id === null) {
            throw InvariantViolation::because('This story is not a fork of anything');
        }

        $base = $this->releases->get(new ReleaseId($fork->forked_from_release_id));

        // Nothing changed means nothing to propose. Opening an empty proposal
        // costs the other author a review of a diff that does not exist.
        if ($this->overrides->overlayFor($forkId, $base->content)->isUntouched()) {
            throw InvariantViolation::because('This fork has no changes to propose');
        }

        $pr = PullRequest::open($base->storyId, $base->id, $forkId, $author, $command->title, $this->clock->now());
        $this->pullRequests->save($pr);

        return $pr;
    }
}
