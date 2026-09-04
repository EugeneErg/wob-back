<?php

declare(strict_types=1);

namespace Wob\Library\Domain\Model;

use Wob\Library\Domain\ValueObject\AssetId;
use Wob\Library\Domain\ValueObject\Dimensions;
use Wob\Library\Domain\ValueObject\EntityPlacement;
use Wob\Library\Domain\ValueObject\Gravity;
use Wob\Library\Domain\ValueObject\LevelId;
use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * A level inside a story. Not an aggregate root: it is loaded and saved as part
 * of its story, because the rules that matter — which levels a chapter map may
 * point at, which levels survive deleting a chapter — are rules about the story
 * as a whole.
 *
 * Levels belong to the story rather than to a chapter, because a level may sit
 * on two chapter maps at once; the client already accounts for this when it
 * refuses to delete a level that is still used elsewhere.
 */
final class Level
{
    /**
     * @param list<EntityPlacement> $entities
     * @param list<AssetId>         $hot
     */
    public function __construct(
        public readonly LevelId $id,
        private string $name,
        private Dimensions $dimensions,
        private Gravity $gravity,
        private int $goal,
        private array $entities,
        private array $hot = [],

        // A still shown on the map node. Not in hashableContent() below,
        // deliberately: swapping a picture must not invalidate a single
        // recorded run, for the same reason renaming a level does not.
        //
        // The film that follows a level lives on the point, not here — the same
        // level met twice can end two different ways.
        private string $image = '',
    ) {
        $this->rename($name);
        $this->setGoal($goal);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function dimensions(): Dimensions
    {
        return $this->dimensions;
    }

    public function gravity(): Gravity
    {
        return $this->gravity;
    }

    public function goal(): int
    {
        return $this->goal;
    }

    /** @return list<EntityPlacement> */
    public function entities(): array
    {
        return $this->entities;
    }

    /** @return list<AssetId> */
    public function hot(): array
    {
        return $this->hot;
    }

    public function rename(string $name): void
    {
        $name = trim($name);

        if ($name === "" || mb_strlen($name) > 200) {
            throw InvariantViolation::because("Level name must be 1-200 characters");
        }

        $this->name = $name;
    }

    public function resize(Dimensions $dimensions): void
    {
        $this->dimensions = $dimensions;
    }

    public function setGravity(Gravity $gravity): void
    {
        $this->gravity = $gravity;
    }

    public function setGoal(int $goal): void
    {
        if ($goal < 0 || $goal > 9999) {
            throw InvariantViolation::because("Level goal must be between 0 and 9999");
        }

        $this->goal = $goal;
    }

    /** @param list<EntityPlacement> $entities */
    public function replaceEntities(array $entities): void
    {
        $seen = [];

        foreach ($entities as $entity) {
            if (isset($seen[$entity->id])) {
                throw InvariantViolation::because(sprintf("Duplicate entity id \"%s\" in level", $entity->id));
            }

            $seen[$entity->id] = true;
        }

        // A child must name a parent that exists in the same level, or the world
        // would build a coordinate system out of nothing every frame.
        foreach ($entities as $entity) {
            if ($entity->parent !== null && !isset($seen[$entity->parent])) {
                throw InvariantViolation::because(
                    sprintf("Entity \"%s\" is attached to \"%s\", which is not in this level", $entity->id, $entity->parent),
                );
            }
        }

        $this->entities = array_values($entities);
    }

    /** @param list<AssetId> $hot */
    public function setHot(array $hot): void
    {
        $this->hot = array_values($hot);
    }

    /**
     * The exact shape core/releases.js feeds to its hash: name, map position and
     * hot assets are excluded on purpose. Renaming a level must not invalidate
     * anyone records.
     *
     * @return array<string, mixed>
     */
    public function setImage(string $image): void
    {
        $this->image = $image;
    }

    public function image(): string
    {
        return $this->image;
    }

    public function hashableContent(): array
    {
        return [
            "id" => $this->id->value,
            "width" => $this->dimensions->width,
            "height" => $this->dimensions->height,
            "gravity" => (object) $this->gravity->toArray(),
            "goal" => $this->goal,
            "entities" => array_map(static fn (EntityPlacement $e): object => $e->jsonSerialize(), $this->entities),
        ];
    }
}
