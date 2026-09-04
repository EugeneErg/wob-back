<?php

declare(strict_types=1);

namespace Wob\Library\Infrastructure\Persistence\Database;

use Illuminate\Database\ConnectionInterface;
use Wob\Library\Application\Query\LibraryReadModel;

final readonly class DatabaseLibraryReadModel implements LibraryReadModel
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function shelfOf(string $ownerId): array
    {
        // Summaries only. A shelf with fifty stories would otherwise ship every
        // entity of every level just to draw fifty covers.
        $stories = $this->db->table("stories")
            ->where("owner_id", $ownerId)
            ->orderBy("created_at")
            ->get()
            ->map(fn (object $s): array => [
                "id" => $s->public_id,
                "title" => $s->title,
                "cover" => $s->cover,
                "startNodeId" => $s->start_node_id,
                "intro" => $s->intro,
                "hot" => $this->decode($s->hot),
                "hash" => $s->content_hash,
                "version" => (int) $s->version,
                "chapters" => $this->db->table("chapters")
                    ->where("story_id", $s->id)
                    ->orderBy("position")
                    ->pluck("public_id")
                    ->all(),
                "updatedAt" => $s->updated_at,
            ])
            ->all();

        $assets = $this->db->table("assets")
            ->where("owner_id", $ownerId)
            ->orderBy("created_at")
            ->get()
            ->map(fn (object $a): array => [
                "id" => $a->public_id,
                "title" => $a->title,
                "entities" => $this->decode($a->entities),
            ])
            ->all();

        return ["stories" => $stories, "assets" => $assets];
    }

    public function story(string $storyId, string $ownerId): ?array
    {
        $story = $this->db->table("stories")
            ->where("public_id", $storyId)
            ->where("owner_id", $ownerId)
            ->first();

        if ($story === null) {
            return null;
        }

        $chapters = $this->db->table("chapters")
            ->where("story_id", $story->id)
            ->orderBy("position")
            ->get()
            ->map(fn (object $c): array => [
                "id" => $c->public_id,
                "storyId" => $story->public_id,
                "title" => $c->title,
                "image" => $c->image,
                "map" => $c->map,
                "canvas" => [
                    "x" => (float) $c->canvas_x, "y" => (float) $c->canvas_y,
                    "w" => (float) $c->canvas_w, "h" => (float) $c->canvas_h,
                ],
                "nodes" => $this->decode($c->nodes),
                "hot" => $this->decode($c->hot),
                "hash" => $c->content_hash,
            ])
            ->all();

        $levels = $this->db->table("levels")
            ->where("story_id", $story->id)
            ->orderBy("created_at")
            ->get()
            ->map($this->levelRow(...))
            ->all();

        return [
            "id" => $story->public_id,
            "title" => $story->title,
            "cover" => $story->cover,
            "startNodeId" => $story->start_node_id,
            "intro" => $story->intro,
            "hot" => $this->decode($story->hot),
            "hash" => $story->content_hash,
            "version" => (int) $story->version,
            "chapters" => $chapters,
            "levels" => $levels,
        ];
    }

    public function storyBundle(string $storyId, string $ownerId): ?array
    {
        $story = $this->story($storyId, $ownerId);

        if ($story === null) {
            return null;
        }

        return $this->bundle('story', [$story], $ownerId);
    }

    public function libraryBundle(string $ownerId): array
    {
        $stories = [];

        foreach ($this->db->table('stories')->where('owner_id', $ownerId)->pluck('public_id') as $id) {
            $full = $this->story((string) $id, $ownerId);

            if ($full !== null) {
                $stories[] = $full;
            }
        }

        return $this->bundle('library', $stories, $ownerId);
    }

    /**
     * @param list<array<string, mixed>> $stories
     *
     * @return array<string, mixed>
     */
    private function bundle(string $kind, array $stories, string $ownerId): array
    {
        $chapters = [];
        $levels = [];
        $storyEntries = [];
        $hot = [];

        foreach ($stories as $story) {
            $storyEntries[] = [
                'id' => $story['id'],
                'title' => $story['title'],
                'cover' => $story['cover'],
                'chapters' => array_column($story['chapters'], 'id'),
                'hot' => $story['hot'],
            ];

            $hot = [...$hot, ...$story['hot']];

            foreach ($story['chapters'] as $chapter) {
                unset($chapter['hash']);
                $chapters[] = $chapter;
                $hot = [...$hot, ...$chapter['hot']];
            }

            foreach ($story['levels'] as $level) {
                unset($level['hash']);
                $levels[] = $level;
                $hot = [...$hot, ...$level['hot']];
            }
        }

        // Only the assets somebody actually marked hot travel with the file. The
        // rest of the shelf is the author's workbench, not part of the story,
        // and shipping it would make every export carry their whole palette.
        $wanted = array_values(array_unique($hot));

        $assets = $wanted === [] ? [] : $this->db->table('assets')
            ->where('owner_id', $ownerId)
            ->whereIn('public_id', $wanted)
            ->get()
            ->map(fn (object $a): array => [
                'id' => $a->public_id,
                'title' => $a->title,
                'entities' => $this->decode($a->entities),
            ])
            ->all();

        return [
            'format' => 'goo-bundle',
            'version' => 1,
            'kind' => $kind,
            'stories' => $storyEntries,
            'chapters' => $chapters,
            'levels' => $levels,
            'assets' => $assets,
        ];
    }

    public function levelByHash(string $hash, string $ownerId): ?array
    {
        $row = $this->db->table("levels")
            ->join("stories", "stories.id", "=", "levels.story_id")
            ->where("stories.owner_id", $ownerId)
            ->where("levels.content_hash", $hash)
            ->select("levels.*")
            ->first();

        return $row === null ? null : $this->levelRow($row);
    }

    /** @return array<string, mixed> */
    private function levelRow(object $l): array
    {
        return [
            "id" => $l->public_id,
            "name" => $l->name,
            "image" => $l->image,
            "width" => (int) $l->width,
            "height" => (int) $l->height,
            "gravity" => $this->decode($l->gravity),
            "goal" => (int) $l->goal,
            "entities" => $this->decode($l->entities),
            "hot" => $this->decode($l->hot),
            "hash" => $l->content_hash,
        ];
    }

    private function decode(string $json): mixed
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
