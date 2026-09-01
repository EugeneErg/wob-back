<?php

declare(strict_types=1);

namespace Wob\Publishing\Infrastructure\Persistence\Database;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Domain\Model\PullRequest;
use Wob\Publishing\Domain\Repository\PullRequestRepository;
use Wob\Publishing\Domain\ValueObject\PullRequestId;
use Wob\Publishing\Domain\ValueObject\ReleaseId;

final readonly class DatabasePullRequestRepository implements PullRequestRepository
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function find(PullRequestId $id): ?PullRequest
    {
        $row = $this->db->table('pull_requests')->where('id', $id->value)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function forStory(StoryId $storyId, ?string $state = null): array
    {
        $rows = $this->db->table('pull_requests')
            ->join('stories', 'stories.id', '=', 'pull_requests.target_story_id')
            ->where('stories.public_id', $storyId->value)
            ->when($state !== null, static fn ($q) => $q->where('pull_requests.state', $state))
            ->orderByDesc('pull_requests.created_at')
            ->select('pull_requests.*')
            ->get();

        return $rows->map($this->hydrate(...))->all();
    }

    public function openedBy(string $authorId): array
    {
        $rows = $this->db->table('pull_requests')
            ->where('author_id', $authorId)
            ->orderByDesc('created_at')
            ->get();

        return $rows->map($this->hydrate(...))->all();
    }

    public function save(PullRequest $pr): void
    {
        $this->db->table('pull_requests')->upsert(
            [[
                'id' => $pr->id->value,
                'target_story_id' => $this->uuid($pr->targetStoryId),
                'base_release_id' => $pr->baseReleaseId->value,
                'fork_story_id' => $this->uuid($pr->forkStoryId),
                'author_id' => $pr->authorId->value,
                'title' => $pr->title,
                'state' => $pr->state(),
                'closed_at' => $pr->closedAt(),
                'created_at' => $pr->openedAt,
                'updated_at' => now(),
            ]],
            ['id'],
            ['state', 'closed_at', 'updated_at'],
        );
    }

    private function hydrate(object $row): PullRequest
    {
        return PullRequest::reconstitute(
            new PullRequestId($row->id),
            new StoryId($this->publicId($row->target_story_id)),
            new ReleaseId($row->base_release_id),
            new StoryId($this->publicId($row->fork_story_id)),
            new OwnerId($row->author_id),
            $row->title,
            new DateTimeImmutable($row->created_at),
            $row->state,
            $row->closed_at === null ? null : new DateTimeImmutable($row->closed_at),
        );
    }

    private function uuid(StoryId $storyId): string
    {
        return (string) $this->db->table('stories')->where('public_id', $storyId->value)->value('id');
    }

    private function publicId(string $uuid): string
    {
        return (string) $this->db->table('stories')->where('id', $uuid)->value('public_id');
    }
}
