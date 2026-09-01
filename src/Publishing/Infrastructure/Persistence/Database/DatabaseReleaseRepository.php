<?php

declare(strict_types=1);

namespace Wob\Publishing\Infrastructure\Persistence\Database;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Domain\Model\Release;
use Wob\Publishing\Domain\Repository\ReleaseRepository;
use Wob\Publishing\Domain\ValueObject\ContentSnapshot;
use Wob\Publishing\Domain\ValueObject\ReleaseId;
use Wob\Shared\Domain\Exception\NotFound;

final readonly class DatabaseReleaseRepository implements ReleaseRepository
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function get(ReleaseId $id): Release
    {
        return $this->find($id) ?? throw NotFound::of('Release', $id->value);
    }

    public function find(ReleaseId $id): ?Release
    {
        return $this->hydrate($this->db->table('releases')->where('id', $id->value)->first());
    }

    public function ofStory(StoryId $storyId): array
    {
        $rows = $this->db->table('releases')
            ->join('stories', 'stories.id', '=', 'releases.story_id')
            ->where('stories.public_id', $storyId->value)
            ->orderByDesc('releases.number')
            ->select('releases.*')
            ->get()
            ->all();

        return array_values(array_filter(array_map($this->hydrate(...), $rows)));
    }

    public function latestOf(StoryId $storyId): ?Release
    {
        return $this->ofStory($storyId)[0] ?? null;
    }

    public function nextNumberFor(StoryId $storyId): int
    {
        $highest = $this->db->table('releases')
            ->join('stories', 'stories.id', '=', 'releases.story_id')
            ->where('stories.public_id', $storyId->value)
            ->max('releases.number');

        return ((int) $highest) + 1;
    }

    public function findByContentHash(string $hash): ?Release
    {
        return $this->hydrate(
            $this->db->table('releases')->where('content_hash', $hash)->orderByDesc('number')->first(),
        );
    }

    public function save(Release $release): void
    {
        $storyUuid = $this->db->table('stories')
            ->where('public_id', $release->storyId->value)
            ->value('id');

        if ($storyUuid === null) {
            throw NotFound::of('Story', $release->storyId->value);
        }

        $values = [
            'story_id' => $storyUuid,
            'number' => $release->number,
            'content' => json_encode(
                ['chapters' => $release->content->chapters, 'levels' => $release->content->levels],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
            'content_hash' => $release->contentHash,
            'previous_release_id' => $release->previousReleaseId?->value,
            'author_cleared_at' => $release->authorClearedAt(),
            'updated_at' => now(),
        ];

        $this->db->table('releases')->upsert(
            [['id' => $release->id->value, ...$values, 'created_at' => $release->releasedAt]],
            ['id'],
            ['author_cleared_at', 'updated_at'],
        );
    }

    private function hydrate(?object $row): ?Release
    {
        if ($row === null) {
            return null;
        }

        $storyPublicId = $this->db->table('stories')->where('id', $row->story_id)->value('public_id');
        $content = json_decode($row->content, false, 512, JSON_THROW_ON_ERROR);

        return Release::reconstitute(
            new ReleaseId($row->id),
            new StoryId((string) $storyPublicId),
            (int) $row->number,
            new ContentSnapshot($content->chapters ?? [], $content->levels ?? []),
            $row->content_hash,
            $row->previous_release_id === null ? null : new ReleaseId($row->previous_release_id),
            new DateTimeImmutable($row->created_at),
            $row->author_cleared_at === null ? null : new DateTimeImmutable($row->author_cleared_at),
        );
    }
}
