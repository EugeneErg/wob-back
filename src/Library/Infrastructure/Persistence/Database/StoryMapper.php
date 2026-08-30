<?php

declare(strict_types=1);

namespace Wob\Library\Infrastructure\Persistence\Database;

use stdClass;
use Wob\Library\Domain\Model\Chapter;
use Wob\Library\Domain\Model\Level;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\ValueObject\AssetId;
use Wob\Library\Domain\ValueObject\ChapterId;
use Wob\Library\Domain\ValueObject\Dimensions;
use Wob\Library\Domain\ValueObject\EntityPlacement;
use Wob\Library\Domain\ValueObject\Gravity;
use Wob\Library\Domain\ValueObject\LevelId;
use Wob\Library\Domain\ValueObject\MapEdge;
use Wob\Library\Domain\ValueObject\MapNode;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;

/**
 * Rows in, aggregate out — and back.
 *
 * A separate class rather than fromArray()/toArray() on the model, because the
 * moment persistence details live on the aggregate, the aggregate starts
 * getting shaped by them: nullable fields that exist only because a column is
 * nullable, setters that exist only so hydration can reach in.
 *
 * JSON is decoded with objects as stdClass, never as associative arrays. PHP
 * cannot tell {} from [] once decoded, and that distinction survives into the
 * content hash: get it wrong and every fingerprint disagrees with the client.
 */
final class StoryMapper
{
    /**
     * @param object       $story
     * @param list<object> $chapterRows
     * @param list<object> $levelRows
     */
    public function toDomain(object $story, array $chapterRows, array $levelRows): Story
    {
        $levels = array_map($this->levelToDomain(...), $levelRows);
        $chapters = array_map($this->chapterToDomain(...), $chapterRows);

        return new Story(
            new StoryId($story->public_id),
            new OwnerId($story->owner_id),
            $story->title,
            $story->cover,
            $chapters,
            $levels,
            $this->assetIds($story->hot),
            (int) $story->version,
        );
    }

    private function chapterToDomain(object $row): Chapter
    {
        $nodes = array_map(
            static fn (stdClass $n): MapNode => new MapNode(
                new LevelId($n->levelId),
                (float) $n->x,
                (float) $n->y,
                isset($n->next) ? new ChapterId($n->next) : null,
            ),
            $this->decodeList($row->nodes),
        );

        $edges = array_map(
            static fn (stdClass $e): MapEdge => new MapEdge(new LevelId($e->from), new LevelId($e->to)),
            $this->decodeList($row->edges),
        );

        return new Chapter(
            new ChapterId($row->public_id),
            $row->title,
            $row->image,
            $nodes,
            $edges,
            $this->assetIds($row->hot),
        );
    }

    private function levelToDomain(object $row): Level
    {
        return new Level(
            new LevelId($row->public_id),
            $row->name,
            new Dimensions((int) $row->width, (int) $row->height),
            Gravity::fromArray((array) $this->decode($row->gravity)),
            (int) $row->goal,
            array_map(EntityPlacement::fromObject(...), $this->decodeList($row->entities)),
            $this->assetIds($row->hot),
        );
    }

    /** @return array<string, mixed> */
    public function storyToRow(Story $story, string $contentHash): array
    {
        return [
            "public_id" => $story->id->value,
            "owner_id" => $story->ownerId->value,
            "title" => $story->title(),
            "cover" => $story->cover(),
            "hot" => $this->encode(array_map(static fn (AssetId $a): string => $a->value, $story->hot())),
            "content_hash" => $contentHash,
            "version" => $story->version(),
        ];
    }

    /** @return array<string, mixed> */
    public function chapterToRow(Chapter $chapter, int $position, string $contentHash): array
    {
        $nodes = array_map(static function (MapNode $n): stdClass {
            $out = new stdClass();
            $out->levelId = $n->levelId->value;
            $out->x = $n->x;
            $out->y = $n->y;

            if ($n->next !== null) {
                $out->next = $n->next->value;
            }

            return $out;
        }, $chapter->nodes());

        $edges = array_map(static function (MapEdge $e): stdClass {
            $out = new stdClass();
            $out->from = $e->from->value;
            $out->to = $e->to->value;

            return $out;
        }, $chapter->edges());

        return [
            "public_id" => $chapter->id->value,
            "title" => $chapter->title(),
            "image" => $chapter->image(),
            "nodes" => $this->encode($nodes),
            "edges" => $this->encode($edges),
            "hot" => $this->encode(array_map(static fn (AssetId $a): string => $a->value, $chapter->hot())),
            "position" => $position,
            "content_hash" => $contentHash,
        ];
    }

    /** @return array<string, mixed> */
    public function levelToRow(Level $level, string $contentHash): array
    {
        return [
            "public_id" => $level->id->value,
            "name" => $level->name(),
            "width" => $level->dimensions()->width,
            "height" => $level->dimensions()->height,
            "gravity" => $this->encode($level->gravity()->toArray()),
            "goal" => $level->goal(),
            "entities" => $this->encode(array_map(
                static fn (EntityPlacement $e): stdClass => $e->jsonSerialize(),
                $level->entities(),
            )),
            "hot" => $this->encode(array_map(static fn (AssetId $a): string => $a->value, $level->hot())),
            "content_hash" => $contentHash,
        ];
    }

    /** @return list<AssetId> */
    private function assetIds(string $json): array
    {
        return array_map(static fn (string $id): AssetId => new AssetId($id), $this->decodeList($json));
    }

    /** @return list<mixed> */
    private function decodeList(string $json): array
    {
        $decoded = $this->decode($json);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function decode(string $json): mixed
    {
        return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
