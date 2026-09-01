<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Repository;

use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Domain\Model\PullRequest;
use Wob\Publishing\Domain\ValueObject\PullRequestId;

interface PullRequestRepository
{
    public function find(PullRequestId $id): ?PullRequest;

    /** @return list<PullRequest> */
    public function forStory(StoryId $storyId, ?string $state = null): array;

    /** @return list<PullRequest> */
    public function openedBy(string $authorId): array;

    public function save(PullRequest $pr): void;
}
