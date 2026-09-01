<?php

declare(strict_types=1);

namespace Wob\Publishing\Infrastructure\Persistence\Database;

use stdClass;
use Illuminate\Database\ConnectionInterface;
use Ramsey\Uuid\Uuid;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Domain\Model\ForkOverlay;
use Wob\Publishing\Domain\Repository\ForkOverrideRepository;
use Wob\Publishing\Domain\ValueObject\ContentSnapshot;
use Wob\Shared\Domain\Exception\NotFound;

final readonly class DatabaseForkOverrideRepository implements ForkOverrideRepository
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function overlayFor(StoryId $forkId, ContentSnapshot $base): ForkOverlay
    {
        $rows = $this->db->table('fork_overrides')
            ->where('story_id', $this->uuid($forkId))
            ->get();

        $levels = [];
        $chapters = [];
        $deletedLevels = [];
        $deletedChapters = [];

        foreach ($rows as $row) {
            // Null content is the tombstone. Absence means "not touched, read
            // the base"; this means "deleted here", and the two must not be
            // confused or a removed level would come back on the next read.
            $deleted = $row->content === null;
            $content = $deleted ? null : json_decode($row->content, false, 512, JSON_THROW_ON_ERROR);

            if ($row->kind === 'level') {
                $deleted ? $deletedLevels[] = $row->public_id : $levels[$row->public_id] = $content;
            } else {
                $deleted ? $deletedChapters[] = $row->public_id : $chapters[$row->public_id] = $content;
            }
        }

        return new ForkOverlay($base, $levels, $chapters, $deletedLevels, $deletedChapters);
    }

    public function put(StoryId $forkId, string $kind, string $publicId, stdClass $content): void
    {
        $this->write($forkId, $kind, $publicId, json_encode($content, JSON_THROW_ON_ERROR));
    }

    public function remove(StoryId $forkId, string $kind, string $publicId): void
    {
        $this->write($forkId, $kind, $publicId, null);
    }

    private function write(StoryId $forkId, string $kind, string $publicId, ?string $content): void
    {
        $this->db->table('fork_overrides')->upsert(
            [[
                'id' => Uuid::uuid4()->toString(),
                'story_id' => $this->uuid($forkId),
                'kind' => $kind,
                'public_id' => $publicId,
                'content' => $content,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['story_id', 'kind', 'public_id'],
            ['content', 'updated_at'],
        );
    }

    private function uuid(StoryId $storyId): string
    {
        $uuid = $this->db->table('stories')->where('public_id', $storyId->value)->value('id');

        return $uuid === null ? throw NotFound::of('Story', $storyId->value) : (string) $uuid;
    }
}
