<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Model;

use DateTimeImmutable;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Domain\ValueObject\PullRequestId;
use Wob\Publishing\Domain\ValueObject\ReleaseId;
use Wob\Shared\Domain\AggregateRoot;
use Wob\Shared\Domain\Exception\AccessDenied;
use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * Someone's fork, offered back to the story it came from.
 *
 * Accepted or rejected whole — never piecemeal. The temptation is to let an
 * author take two of the five levels in a proposal, and it is worth naming why
 * that is refused: the server does not understand entity data, so it cannot
 * offer a meaningful line-by-line merge the way git does. A half-taken
 * proposal would be a set of levels nobody, author or contributor, ever
 * played together — and the contributor's chapter map may well assume all five
 * changes. Whole-or-nothing keeps every accepted state a state that somebody
 * actually tested.
 *
 * The proposal carries no content of its own. It points at the fork, and the
 * fork's overlay IS the change. Snapshotting the diff into this aggregate
 * would freeze it at open time, and then a contributor polishing their fork
 * after review comments would be silently proposing their old work.
 */
final class PullRequest extends AggregateRoot
{
    public const OPEN = 'open';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const WITHDRAWN = 'withdrawn';

    private string $state = self::OPEN;
    private ?DateTimeImmutable $closedAt = null;

    private function __construct(
        public readonly PullRequestId $id,
        public readonly StoryId $targetStoryId,
        public readonly ReleaseId $baseReleaseId,
        public readonly StoryId $forkStoryId,
        public readonly OwnerId $authorId,
        public readonly string $title,
        public readonly DateTimeImmutable $openedAt,
    ) {
        if (trim($title) === '' || mb_strlen($title) > 200) {
            throw InvariantViolation::because('A proposal needs a title of 1-200 characters');
        }
    }

    public static function open(
        StoryId $targetStoryId,
        ReleaseId $baseReleaseId,
        StoryId $forkStoryId,
        OwnerId $authorId,
        string $title,
        DateTimeImmutable $now,
    ): self {
        return new self(PullRequestId::next(), $targetStoryId, $baseReleaseId, $forkStoryId, $authorId, $title, $now);
    }

    public static function reconstitute(
        PullRequestId $id,
        StoryId $targetStoryId,
        ReleaseId $baseReleaseId,
        StoryId $forkStoryId,
        OwnerId $authorId,
        string $title,
        DateTimeImmutable $openedAt,
        string $state,
        ?DateTimeImmutable $closedAt,
    ): self {
        $pr = new self($id, $targetStoryId, $baseReleaseId, $forkStoryId, $authorId, $title, $openedAt);
        $pr->state = $state;
        $pr->closedAt = $closedAt;

        return $pr;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function isOpen(): bool
    {
        return $this->state === self::OPEN;
    }

    public function closedAt(): ?DateTimeImmutable
    {
        return $this->closedAt;
    }

    /**
     * Accepting applies the fork's overrides to the target author's draft. It
     * does NOT publish: the result is a commit on a draft, and the author still
     * has to cut a release before anyone plays it. That separation is the whole
     * point of having releases — accepting a proposal should never be able to
     * change what players are currently playing.
     */
    public function acceptedBy(OwnerId $storyOwner, OwnerId $actor, DateTimeImmutable $at): void
    {
        $this->assertActionable($storyOwner, $actor);
        $this->state = self::ACCEPTED;
        $this->closedAt = $at;
    }

    public function rejectedBy(OwnerId $storyOwner, OwnerId $actor, DateTimeImmutable $at): void
    {
        $this->assertActionable($storyOwner, $actor);
        $this->state = self::REJECTED;
        $this->closedAt = $at;
    }

    /**
     * The contributor pulling their own proposal. Distinct from rejection so
     * the two are not confused later: "the author said no" and "I changed my
     * mind" are different pieces of history, and a fork's reputation should
     * not carry a rejection it never received.
     */
    public function withdrawnBy(OwnerId $actor, DateTimeImmutable $at): void
    {
        if (!$this->authorId->equals($actor)) {
            throw AccessDenied::of('Pull request', $this->id->value);
        }

        $this->assertOpen();
        $this->state = self::WITHDRAWN;
        $this->closedAt = $at;
    }

    private function assertActionable(OwnerId $storyOwner, OwnerId $actor): void
    {
        if (!$storyOwner->equals($actor)) {
            throw AccessDenied::of('Pull request', $this->id->value);
        }

        $this->assertOpen();
    }

    private function assertOpen(): void
    {
        if (!$this->isOpen()) {
            throw InvariantViolation::because(
                sprintf('This proposal is already %s', $this->state),
            );
        }
    }
}
