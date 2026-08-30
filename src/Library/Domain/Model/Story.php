<?php

declare(strict_types=1);

namespace Wob\Library\Domain\Model;

use Wob\Library\Domain\Event\StoryDeleted;
use Wob\Library\Domain\Event\LevelsDiscarded;
use Wob\Library\Domain\Service\ContentHasher;
use Wob\Library\Domain\ValueObject\AssetId;
use Wob\Library\Domain\ValueObject\ChapterId;
use Wob\Library\Domain\ValueObject\ContentHash;
use Wob\Library\Domain\ValueObject\LevelId;
use Wob\Library\Domain\ValueObject\MapNode;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Shared\Domain\AggregateRoot;
use Wob\Shared\Domain\Exception\AccessDenied;
use Wob\Shared\Domain\Exception\InvariantViolation;
use Wob\Shared\Domain\Exception\NotFound;

/**
 * A story with its chapters and levels — the aggregate.
 *
 * The client keeps stories, chapters, levels and assets as four flat lists
 * joined by id, which is the right shape for localStorage and the wrong shape
 * for a server. Flat lists cannot express the rules that actually hold, and
 * every one of those rules spans more than one list:
 *
 *   - a chapter map may only pin levels of its own story;
 *   - a path may only join levels that are on that same map;
 *   - an exit may only lead to a chapter of this story;
 *   - deleting a chapter drops the levels no other chapter uses, and quietly
 *     clears the exits that used to lead into it.
 *
 * Written as free functions over flat tables, those rules have nowhere to live
 * and get re-implemented, differently, in every endpoint that touches content.
 * Written here, they are enforced by the only object that is allowed to change
 * any of it.
 *
 * The boundary is also the transaction boundary and the locking boundary: one
 * story is loaded, changed and saved as a unit, with one version number. Two
 * editors on two different stories never contend.
 */
final class Story extends AggregateRoot
{
    /** Version 0 means "not yet in the database". */
    public const NEW = 0;

    /** @var array<string, Chapter> keyed by chapter id, insertion order is chapter order */
    private array $chapters = [];

    /** @var array<string, Level> keyed by level id */
    private array $levels = [];

    /**
     * @param list<Chapter> $chapters
     * @param list<Level>   $levels
     * @param list<AssetId> $hot
     */
    public function __construct(
        public readonly StoryId $id,
        public readonly OwnerId $ownerId,
        private string $title,
        private string $cover,
        array $chapters = [],
        array $levels = [],
        private array $hot = [],
        private int $version = self::NEW,
    ) {
        $this->rename($title);

        foreach ($levels as $level) {
            $this->levels[$level->id->value] = $level;
        }

        foreach ($chapters as $chapter) {
            $this->chapters[$chapter->id->value] = $chapter;
        }

        $this->assertReferencesResolve();
    }

    // ---- reading ------------------------------------------------------------

    public function title(): string
    {
        return $this->title;
    }

    public function cover(): string
    {
        return $this->cover;
    }

    public function version(): int
    {
        return $this->version;
    }

    /** @return list<Chapter> */
    public function chapters(): array
    {
        return array_values($this->chapters);
    }

    /** @return list<Level> */
    public function levels(): array
    {
        return array_values($this->levels);
    }

    /** @return list<AssetId> */
    public function hot(): array
    {
        return $this->hot;
    }

    public function chapter(ChapterId $id): Chapter
    {
        return $this->chapters[$id->value] ?? throw NotFound::of("Chapter", $id->value);
    }

    public function level(LevelId $id): Level
    {
        return $this->levels[$id->value] ?? throw NotFound::of("Level", $id->value);
    }

    public function isOwnedBy(OwnerId $ownerId): bool
    {
        return $this->ownerId->equals($ownerId);
    }

    /**
     * Kept although the repository already scopes every lookup to an owner.
     *
     * Belt and braces on purpose: this is the invariant, and the repository is
     * one implementation of it. A future read path that forgets the owner clause
     * should fail loudly here rather than quietly hand over someone else's work.
     */
    public function assertOwnedBy(OwnerId $ownerId): void
    {
        if (!$this->isOwnedBy($ownerId)) {
            throw AccessDenied::of("Story", $this->id->value);
        }
    }

    // ---- editing ------------------------------------------------------------

    public function rename(string $title): void
    {
        $title = trim($title);

        if ($title === "" || mb_strlen($title) > 200) {
            throw InvariantViolation::because("Story title must be 1-200 characters");
        }

        $this->title = $title;
    }

    public function setCover(string $cover): void
    {
        if (mb_strlen($cover) > 2000) {
            throw InvariantViolation::because("Story cover is too long");
        }

        $this->cover = $cover;
    }

    /** @param list<AssetId> $hot */
    public function setHot(array $hot): void
    {
        $this->hot = array_values($hot);
    }

    public function addChapter(Chapter $chapter): void
    {
        if (isset($this->chapters[$chapter->id->value])) {
            throw InvariantViolation::because(
                sprintf("Chapter %s is already in this story", $chapter->id->value),
            );
        }

        $this->chapters[$chapter->id->value] = $chapter;
        $this->assertReferencesResolve();
    }

    /**
     * Chapter order is the unlock order: a chapter opens when the previous one
     * is finished, so reordering changes what the player may play next.
     *
     * @param list<ChapterId> $order
     */
    public function reorderChapters(array $order): void
    {
        if (count($order) !== count($this->chapters)) {
            throw InvariantViolation::because("Chapter order must list every chapter exactly once");
        }

        $reordered = [];

        foreach ($order as $id) {
            if (!isset($this->chapters[$id->value]) || isset($reordered[$id->value])) {
                throw InvariantViolation::because("Chapter order must list every chapter exactly once");
            }

            $reordered[$id->value] = $this->chapters[$id->value];
        }

        $this->chapters = $reordered;
    }

    /**
     * Removing a chapter is the one operation with real consequences elsewhere,
     * and the client already knows what they are:
     *
     *  - levels that no surviving chapter pins are gone with it, because nothing
     *    can reach them any more and they would sit in the library forever;
     *  - exits that led into this chapter are cleared everywhere. Leaving one
     *    would show a node that looks like a way onward and would let a chapter
     *    count as finished through a road that does not exist.
     */
    public function removeChapter(ChapterId $id): void
    {
        $chapter = $this->chapter($id);
        unset($this->chapters[$id->value]);

        foreach ($this->chapters as $other) {
            $other->forgetExitsTo($id);
        }

        $orphans = [];

        foreach ($chapter->levelIds() as $levelId) {
            if (!$this->isPinnedAnywhere($levelId)) {
                unset($this->levels[$levelId->value]);
                $orphans[] = $levelId;
            }
        }

        if ($orphans !== []) {
            $this->recordThat(new LevelsDiscarded($this->id, $orphans));
        }
    }

    /**
     * A level is created into a chapter: a level nobody can reach is not content,
     * it is litter. The map position comes from the caller because it is a
     * presentation decision, but the pinning happens here so that "every level
     * belongs to at least one map" holds by construction.
     */
    public function addLevel(ChapterId $chapterId, Level $level, MapNode $node): void
    {
        if (isset($this->levels[$level->id->value])) {
            throw InvariantViolation::because(sprintf("Level %s is already in this story", $level->id->value));
        }

        if (!$node->levelId->equals($level->id)) {
            throw InvariantViolation::because("The map node must point at the level being added");
        }

        $this->levels[$level->id->value] = $level;
        $this->chapter($chapterId)->pin($node);
    }

    /**
     * Unpin from one chapter; delete outright only if no other chapter still
     * shows it. Shared levels are legitimate — a hub level can sit on two maps.
     */
    public function removeLevel(ChapterId $chapterId, LevelId $levelId): void
    {
        $this->chapter($chapterId)->unpin($levelId);

        if (!$this->isPinnedAnywhere($levelId)) {
            unset($this->levels[$levelId->value]);
            $this->recordThat(new LevelsDiscarded($this->id, [$levelId]));
        }
    }

    public function delete(): void
    {
        $this->recordThat(new StoryDeleted($this->id, $this->ownerId, array_map(
            static fn (Level $l): LevelId => $l->id,
            array_values($this->levels),
        )));
    }

    // ---- versioning ---------------------------------------------------------

    /**
     * Optimistic locking. The editor is offline-first, so two devices can hold
     * the same story; last-write-wins would silently eat an afternoon of level
     * design. The client sends the version it loaded, and a stale write is
     * refused rather than applied.
     */
    public function expectVersion(int $expected): void
    {
        if ($expected !== $this->version) {
            throw new \Wob\Shared\Domain\Exception\ConcurrentModification($expected, $this->version);
        }
    }

    public function bumpVersion(): int
    {
        return ++$this->version;
    }

    // ---- content fingerprint ------------------------------------------------

    /**
     * The hasher is passed in rather than held, so the aggregate stays free of
     * dependencies and the fingerprint stays a pure function of content.
     */
    public function contentHash(ContentHasher $hasher): ContentHash
    {
        return new ContentHash($hasher->hash([
            "id" => $this->id->value,
            "chapters" => array_map(
                fn (Chapter $c): string => $c->id->value . ":" . $this->chapterHash($hasher, $c)->value,
                array_values($this->chapters),
            ),
        ]));
    }

    public function chapterHash(ContentHasher $hasher, Chapter $chapter): ContentHash
    {
        $levelHashes = [];

        foreach ($chapter->levelIds() as $levelId) {
            $level = $this->levels[$levelId->value] ?? null;
            $levelHashes[$levelId->value] = $level === null ? "null" : $this->levelHash($hasher, $level)->value;
        }

        return new ContentHash($hasher->hash($chapter->hashableContent($levelHashes)));
    }

    public function levelHash(ContentHasher $hasher, Level $level): ContentHash
    {
        return new ContentHash($hasher->hash($level->hashableContent()));
    }

    // ---- invariants ---------------------------------------------------------

    private function isPinnedAnywhere(LevelId $levelId): bool
    {
        foreach ($this->chapters as $chapter) {
            if ($chapter->holds($levelId)) {
                return true;
            }
        }

        return false;
    }

    private function assertReferencesResolve(): void
    {
        foreach ($this->chapters as $chapter) {
            foreach ($chapter->nodes() as $node) {
                if (!isset($this->levels[$node->levelId->value])) {
                    throw InvariantViolation::because(sprintf(
                        "Chapter %s pins level %s, which is not in this story",
                        $chapter->id->value,
                        $node->levelId->value,
                    ));
                }

                if ($node->next !== null && !isset($this->chapters[$node->next->value])) {
                    throw InvariantViolation::because(sprintf(
                        "Chapter %s leads out to chapter %s, which is not in this story",
                        $chapter->id->value,
                        $node->next->value,
                    ));
                }
            }
        }
    }
}
