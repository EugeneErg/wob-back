<?php

declare(strict_types=1);

namespace Wob\Publishing\Infrastructure\Persistence\Database;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Domain\Model\EditSession;
use Wob\Publishing\Domain\Repository\EditSessionRepository;
use Wob\Publishing\Domain\ValueObject\EditSessionId;
use Wob\Publishing\Domain\ValueObject\ReleaseId;

final readonly class DatabaseEditSessionRepository implements EditSessionRepository
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function forEditor(OwnerId $editorId, ReleaseId $baseReleaseId): ?EditSession
    {
        $row = $this->db->table('edit_sessions')
            ->where('editor_id', $editorId->value)
            ->where('base_release_id', $baseReleaseId->value)
            ->first();

        if ($row === null) {
            return null;
        }

        $baseStory = $this->publicId($row->base_story_id);
        $fork = $row->fork_story_id === null ? null : $this->publicId($row->fork_story_id);

        return EditSession::reconstitute(
            new EditSessionId($row->id),
            new OwnerId($row->editor_id),
            new ReleaseId($row->base_release_id),
            new StoryId($baseStory),
            new DateTimeImmutable($row->created_at),
            $fork === null ? null : new StoryId($fork),
        );
    }

    public function save(EditSession $session): void
    {
        $forkId = $session->forkStoryId();

        $this->db->table('edit_sessions')->upsert(
            [[
                'id' => $session->id->value,
                'editor_id' => $session->editorId->value,
                'base_release_id' => $session->baseReleaseId->value,
                'base_story_id' => $this->uuid($session->baseStoryId),
                'fork_story_id' => $forkId === null ? null : $this->uuid($forkId),
                'created_at' => $session->startedAt,
                'updated_at' => now(),
            ]],
            ['id'],
            ['fork_story_id', 'updated_at'],
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
