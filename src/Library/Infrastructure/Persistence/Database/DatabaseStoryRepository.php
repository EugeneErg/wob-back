<?php

declare(strict_types=1);

namespace Wob\Library\Infrastructure\Persistence\Database;

use Illuminate\Database\ConnectionInterface;
use Ramsey\Uuid\Uuid;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\Repository\IdsInUse;
use Wob\Library\Domain\Repository\StoryRepository;
use Wob\Library\Domain\Service\ContentHasher;
use Wob\Library\Domain\ValueObject\LevelId;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Shared\Domain\Exception\ConcurrentModification;
use Wob\Shared\Domain\Exception\NotFound;

/**
 * Loads and saves whole stories.
 *
 * Written against the query builder rather than Eloquent models with relations,
 * for one reason: Eloquent models are Active Record, and Active Record wants to
 * BE the domain model. Once Story extends Model, it inherits save(), a database
 * connection and a lifecycle, and the invariants stop being the only way to
 * change it. Here the aggregate is plain PHP and this class is the only code
 * that knows a database exists.
 *
 * Saving is a diff, not a wipe-and-reinsert: deleting every chapter and writing
 * them back would cascade into level_completions and erase player progress on
 * every single save.
 */
final readonly class DatabaseStoryRepository implements StoryRepository
{
    public function __construct(
        private ConnectionInterface $db,
        private StoryMapper $mapper,
        private ContentHasher $hasher,
    ) {
    }

    public function get(OwnerId $ownerId, StoryId $id): Story
    {
        return $this->find($ownerId, $id) ?? throw NotFound::of("Story", $id->value);
    }

    public function find(OwnerId $ownerId, StoryId $id): ?Story
    {
        $story = $this->db->table("stories")
            ->where("owner_id", $ownerId->value)
            ->where("public_id", $id->value)
            ->first();

        if ($story === null) {
            return null;
        }

        return $this->hydrate($story);
    }

    /** @return list<Story> */
    public function ownedBy(OwnerId $ownerId): array
    {
        $stories = $this->db->table("stories")
            ->where("owner_id", $ownerId->value)
            ->orderBy("created_at")
            ->get()
            ->all();

        return array_map($this->hydrate(...), $stories);
    }

    public function idsInUse(OwnerId $ownerId): IdsInUse
    {
        $ownStories = fn (): array => array_fill_keys(
            $this->db->table("stories")->where("owner_id", $ownerId->value)->pluck("public_id")->all(),
            true,
        );

        // Chapters and levels have no owner column of their own; they belong to
        // whoever owns the story above them.
        $under = fn (string $table): array => array_fill_keys(
            $this->db->table($table)
                ->join("stories", "stories.id", "=", $table . ".story_id")
                ->where("stories.owner_id", $ownerId->value)
                ->pluck($table . ".public_id")
                ->all(),
            true,
        );

        $ownAssets = fn (): array => array_fill_keys(
            $this->db->table("assets")->where("owner_id", $ownerId->value)->pluck("public_id")->all(),
            true,
        );

        return new IdsInUse($ownStories(), $under("chapters"), $under("levels"), $ownAssets());
    }

    public function save(Story $story): void
    {
        $this->db->transaction(function () use ($story): void {
            $row = $this->db->table("stories")
                ->where("owner_id", $story->ownerId->value)
                ->where("public_id", $story->id->value)
                ->lockForUpdate()
                ->first();

            $isNew = $row === null;

            if (!$isNew && (int) $row->version !== $story->version()) {
                // The caller loaded version N, someone else has since written
                // N+1. Refusing is the whole point: applying it would silently
                // throw away whatever that other write contained.
                throw new ConcurrentModification($story->version(), (int) $row->version);
            }

            $story->bumpVersion();
            $storyUuid = $isNew ? Uuid::uuid4()->toString() : $row->id;
            $values = $this->mapper->storyToRow($story, $story->contentHash($this->hasher)->value);

            if ($isNew) {
                $this->db->table("stories")->insert([
                    "id" => $storyUuid,
                    ...$values,
                    "created_at" => now(),
                    "updated_at" => now(),
                ]);
            } else {
                $this->db->table("stories")
                    ->where("id", $storyUuid)
                    ->update([...$values, "updated_at" => now()]);
            }

            $this->syncLevels($storyUuid, $story);
            $this->syncChapters($storyUuid, $story);
        });
    }

    public function remove(Story $story): void
    {
        // Chapters and levels go with it by foreign key cascade; so do the
        // completions that pointed at those levels.
        $this->db->table("stories")
            ->where("owner_id", $story->ownerId->value)
            ->where("public_id", $story->id->value)
            ->delete();
    }

    public function storyOfLevel(LevelId $levelId, OwnerId $ownerId): ?StoryId
    {
        $row = $this->db->table("levels")
            ->join("stories", "stories.id", "=", "levels.story_id")
            ->where("levels.public_id", $levelId->value)
            ->where("stories.owner_id", $ownerId->value)
            ->select("stories.public_id")
            ->first();

        return $row === null ? null : new StoryId($row->public_id);
    }

    private function hydrate(object $story): Story
    {
        $chapters = $this->db->table("chapters")
            ->where("story_id", $story->id)
            ->orderBy("position")
            ->get()
            ->all();

        $levels = $this->db->table("levels")
            ->where("story_id", $story->id)
            ->orderBy("created_at")
            ->get()
            ->all();

        return $this->mapper->toDomain($story, $chapters, $levels);
    }

    private function syncLevels(string $storyUuid, Story $story): void
    {
        $existing = $this->db->table("levels")
            ->where("story_id", $storyUuid)
            ->pluck("id", "public_id")
            ->all();

        $keep = [];

        foreach ($story->levels() as $level) {
            $keep[] = $level->id->value;
            $values = $this->mapper->levelToRow($level, $story->levelHash($this->hasher, $level)->value);

            if (isset($existing[$level->id->value])) {
                $this->db->table("levels")
                    ->where("id", $existing[$level->id->value])
                    ->update([...$values, "updated_at" => now()]);
            } else {
                $this->db->table("levels")->insert([
                    "id" => Uuid::uuid4()->toString(),
                    "story_id" => $storyUuid,
                    ...$values,
                    "created_at" => now(),
                    "updated_at" => now(),
                ]);
            }
        }

        $gone = array_diff(array_keys($existing), $keep);

        if ($gone !== []) {
            $this->db->table("levels")
                ->where("story_id", $storyUuid)
                ->whereIn("public_id", array_values($gone))
                ->delete();
        }
    }

    private function syncChapters(string $storyUuid, Story $story): void
    {
        $existing = $this->db->table("chapters")
            ->where("story_id", $storyUuid)
            ->pluck("id", "public_id")
            ->all();

        $keep = [];
        $position = 0;

        foreach ($story->chapters() as $chapter) {
            $keep[] = $chapter->id->value;
            $values = $this->mapper->chapterToRow(
                $chapter,
                $position++,
                $story->chapterHash($this->hasher, $chapter)->value,
            );

            if (isset($existing[$chapter->id->value])) {
                $this->db->table("chapters")
                    ->where("id", $existing[$chapter->id->value])
                    ->update([...$values, "updated_at" => now()]);
            } else {
                $this->db->table("chapters")->insert([
                    "id" => Uuid::uuid4()->toString(),
                    "story_id" => $storyUuid,
                    ...$values,
                    "created_at" => now(),
                    "updated_at" => now(),
                ]);
            }
        }

        $gone = array_diff(array_keys($existing), $keep);

        if ($gone !== []) {
            $this->db->table("chapters")
                ->where("story_id", $storyUuid)
                ->whereIn("public_id", array_values($gone))
                ->delete();
        }
    }
}
