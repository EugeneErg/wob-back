<?php

declare(strict_types=1);

namespace Wob\Library\Domain\Repository;

use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\ValueObject\LevelId;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;

/**
 * Collection-like access to stories. Declared in the domain and implemented in
 * infrastructure, so the direction of the dependency stays inward: the domain
 * states what it needs, the database obeys.
 *
 * There is no repository for Chapter or Level, and that is the point — they are
 * reachable only through their story, which is what makes the invariants in
 * Story enforceable rather than merely documented.
 */
interface StoryRepository
{
    /*
     * Every lookup is scoped to an owner, because a story id only identifies a
     * story together with one. Public ids are minted by the editor and unique
     * per author, so two people can both hold "story-first" — and they will, the
     * moment either of them imports a shared file. A global lookup would hand
     * one author the other's content, and the ownership check afterwards would
     * report it as forbidden rather than absent, which also tells a stranger
     * that the id exists.
     */

    /** @throws \Wob\Shared\Domain\Exception\NotFound */
    public function get(OwnerId $ownerId, StoryId $id): Story;

    public function find(OwnerId $ownerId, StoryId $id): ?Story;

    /** @return list<Story> */
    public function ownedBy(OwnerId $ownerId): array;

    /**
     * Which client-minted ids this author has already used.
     *
     * Scoped to the owner, not global: public ids are unique per author, so two
     * people importing the same shared file each keep the ids it came with.
     * Checking globally would rename one of them for no reason and break the
     * only thing that lets the two copies be recognised as the same story.
     */
    public function idsInUse(OwnerId $ownerId): IdsInUse;

    /** @throws \Wob\Shared\Domain\Exception\ConcurrentModification */
    public function save(Story $story): void;

    public function remove(Story $story): void;

    /**
     * Which story a level belongs to. Progress needs it to key completions, and
     * asking through the aggregate would mean loading every story to find one.
     */
    public function storyOfLevel(LevelId $levelId, OwnerId $ownerId): ?StoryId;
}
