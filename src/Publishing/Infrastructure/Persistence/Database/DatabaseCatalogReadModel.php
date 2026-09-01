<?php

declare(strict_types=1);

namespace Wob\Publishing\Infrastructure\Persistence\Database;

use Illuminate\Database\ConnectionInterface;
use Wob\Publishing\Application\Query\CatalogReadModel;
use Wob\Publishing\Domain\Service\ContentGate;
use Wob\Publishing\Domain\ValueObject\ContentSnapshot;

final readonly class DatabaseCatalogReadModel implements CatalogReadModel
{
    public function __construct(
        private ConnectionInterface $db,
        private ContentGate $gate,
    ) {
    }

    public function canon(): array
    {
        $rows = $this->db->table('stories')
            ->join('releases', 'releases.id', '=', 'stories.canonical_release_id')
            ->whereNotNull('stories.canonical_release_id')
            ->orderBy('stories.canonical_since')
            ->select([
                'stories.public_id',
                'stories.title',
                'stories.cover',
                'stories.canonical_since',
                'releases.id as release_id',
                'releases.number as version',
                'releases.content_hash',
            ])
            ->get();

        return $rows->map($this->summary(...))->all();
    }

    public function published(): array
    {
        // Published but uncrowned: the release the author last cut, cleared by
        // them, on a story that has not made canon. These are what the votes
        // are actually for.
        $latest = $this->db->table('releases')
            ->select('story_id', $this->db->raw('MAX(number) as number'))
            ->whereNotNull('author_cleared_at')
            ->groupBy('story_id');

        $rows = $this->db->table('stories')
            ->joinSub($latest, 'newest', 'newest.story_id', '=', 'stories.id')
            ->join('releases', static function ($join): void {
                $join->on('releases.story_id', '=', 'stories.id')
                    ->on('releases.number', '=', 'newest.number');
            })
            ->whereNull('stories.canonical_release_id')
            ->orderByDesc('releases.created_at')
            ->select([
                'stories.public_id',
                'stories.title',
                'stories.cover',
                'releases.id as release_id',
                'releases.number as version',
                'releases.content_hash',
            ])
            ->get();

        return $rows->map($this->summary(...))->all();
    }

    public function forVisitor(): ?array
    {
        $first = $this->canon()[0] ?? null;

        if ($first === null) {
            return null;
        }

        return [...$first, 'preview' => true];
    }

    public function play(string $storyId, ?string $playerId): ?array
    {
        $row = $this->db->table('stories')
            ->leftJoin('releases', 'releases.id', '=', 'stories.canonical_release_id')
            ->where('stories.public_id', $storyId)
            ->select([
                'stories.public_id',
                'stories.title',
                'stories.canonical_release_id',
                'stories.canonical_since',
                'releases.id as release_id',
                'releases.number as version',
                'releases.content',
                'releases.content_hash',
            ])
            ->first();

        if ($row === null || $row->release_id === null) {
            return null;
        }

        $decoded = json_decode((string) $row->content, false, 512, JSON_THROW_ON_ERROR);
        $content = new ContentSnapshot($decoded->chapters ?? [], $decoded->levels ?? []);

        // Signed out, only the first canonical story is on offer at all, and
        // only a taste of it. Checked here rather than in the controller
        // because the trimming and the eligibility are one decision: a gate
        // that can be reached by a different route is not a gate.
        $preview = $playerId === null;

        if ($preview) {
            $firstCanonical = $this->canon()[0] ?? null;

            if ($firstCanonical === null || $firstCanonical['id'] !== $storyId) {
                return null;
            }

            $content = $this->gate->forVisitor($content);
        }

        return [
            'id' => $row->public_id,
            'title' => $row->title,
            'releaseId' => $row->release_id,
            'version' => (int) $row->version,
            'hash' => $row->content_hash,
            'preview' => $preview,
            'chapters' => $content->chapters,
            'levels' => $content->levels,
        ];
    }

    /** @return array<string, mixed> */
    private function summary(object $row): array
    {
        return [
            'id' => $row->public_id,
            'title' => $row->title,
            'cover' => $row->cover,
            'releaseId' => $row->release_id,
            'version' => (int) $row->version,
            'hash' => $row->content_hash,
        ];
    }
}
