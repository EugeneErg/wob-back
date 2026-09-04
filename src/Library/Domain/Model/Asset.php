<?php

declare(strict_types=1);

namespace Wob\Library\Domain\Model;

use Wob\Library\Domain\ValueObject\AssetId;
use Wob\Library\Domain\ValueObject\EntityPlacement;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Shared\Domain\Exception\InvariantViolation;
use Wob\Shared\Domain\AggregateRoot;

/**
 * A saved piece of a level: one entity or a whole group of them.
 *
 * It held a single entity at first, which made the common case awkward. Things
 * an author wants to reuse are rarely one entity — a motor with the arm it
 * turns, a hazard with the terrain it sits in — and saving them one at a time
 * loses the arrangement, which was the part worth keeping. So an asset is a
 * list, and a single entity is simply a list of one.
 *
 * The list is stored as placements, exactly as a level stores them, so dropping
 * an asset into a level is a copy rather than a translation. Entity ids inside
 * it are the author's own; whoever drops the asset in is responsible for making
 * them unique in the level, the same way importing a bundle rewrites colliding
 * ids rather than trusting them.
 *
 * An aggregate root of its own, not a part of Story, because the shelf is
 * per-author and shared across their stories — createAsset() on the client
 * pushes into one global list, and stories merely mark some of them "hot". A
 * hot list is therefore a reference across an aggregate boundary, by id, which
 * is exactly how such references are supposed to look.
 *
 * The consequence is deliberate: a hot id may outlive the asset it names. The
 * client already tolerates this (it filters missing assets out when building the
 * palette), and the alternative — cascading a delete into every story to keep
 * the lists tidy — would make deleting one asset a write across the whole
 * library. A dangling id costs nothing; a fan-out write costs correctness.
 */
final class Asset extends AggregateRoot
{
    /** @param list<EntityPlacement> $entities */
    public function __construct(
        public readonly AssetId $id,
        public readonly OwnerId $ownerId,
        private string $title,
        private array $entities,
    ) {
        $this->rename($title);
        $this->replaceEntities($entities);
    }

    /**
     * What kinds of entity are inside.
     *
     * The palette groups by this, and a group of several types belongs under
     * all of them: an author looking for the motor finds the motor-with-arm.
     *
     * @return list<string>
     */
    public function types(): array
    {
        return array_values(array_unique(array_map(
            static fn (EntityPlacement $e): string => $e->type,
            $this->entities,
        )));
    }

    public function title(): string
    {
        return $this->title;
    }

    /** @return list<EntityPlacement> */
    public function entities(): array
    {
        return $this->entities;
    }

    public function rename(string $title): void
    {
        $title = trim($title);

        if ($title === "" || mb_strlen($title) > 200) {
            throw InvariantViolation::because("Asset title must be 1-200 characters");
        }

        $this->title = $title;
    }

    /** @param list<EntityPlacement> $entities */
    public function replaceEntities(array $entities): void
    {
        if ($entities === []) {
            throw InvariantViolation::because("An asset must hold at least one entity");
        }

        $seen = [];

        foreach ($entities as $entity) {
            if (isset($seen[$entity->id])) {
                throw InvariantViolation::because(
                    sprintf("Entity %s appears twice in this asset", $entity->id),
                );
            }

            $seen[$entity->id] = true;
        }

        // A child whose parent was left out would arrive in a level attached to
        // nothing. Saving half an arrangement is the one thing this must not do
        // — the arrangement is what an author saved it for.
        foreach ($entities as $entity) {
            if ($entity->parent !== null && !isset($seen[$entity->parent])) {
                throw InvariantViolation::because(sprintf(
                    "Entity %s belongs to %s, which is not in this asset",
                    $entity->id,
                    $entity->parent,
                ));
            }
        }

        $this->entities = array_values($entities);
    }
}
