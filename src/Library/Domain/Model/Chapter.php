<?php

declare(strict_types=1);

namespace Wob\Library\Domain\Model;

use Wob\Library\Domain\ValueObject\AssetId;
use Wob\Library\Domain\ValueObject\ChapterId;
use Wob\Library\Domain\ValueObject\LevelId;
use Wob\Library\Domain\ValueObject\CanvasRect;
use Wob\Library\Domain\ValueObject\MapNode;
use Wob\Library\Domain\ValueObject\NodeId;
use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * A chapter map: levels pinned to a picture, and the paths between them.
 *
 * The chapter holds the graph but deliberately does NOT decide what is
 * unlocked. Unlocking is a question about a particular player, and the answer
 * is a pure function of this graph plus that player progress — it lives in the
 * client today and in the Progress context tomorrow. Putting it here would make
 * content depend on players, which is backwards.
 */
final class Chapter
{
    /**
     * @param list<MapNode> $nodes
     * @param list<AssetId> $hot
     */
    public function __construct(
        public readonly ChapterId $id,
        private string $title,
        private string $image,
        private array $nodes = [],
        private array $hot = [],

        // The picture the level map is drawn on. Presentation only, so it does
        // not reach hashableContent().
        //
        // A chapter has no film of its own. It had one briefly, and that put a
        // wait in front of every map a player opened; the story's plays once
        // and each point's plays after its level, which covers the same beats
        // without repeating one of them.
        private string $map = '',

        // Where this chapter sits on the story board.
        private ?CanvasRect $canvas = null,
    ) {
        $this->rename($title);
        $this->assertGraphIsSound();
    }

    public function title(): string
    {
        return $this->title;
    }

    public function image(): string
    {
        return $this->image;
    }

    /** @return list<MapNode> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /** @return list<AssetId> */
    public function hot(): array
    {
        return $this->hot;
    }

    public function rename(string $title): void
    {
        $title = trim($title);

        if ($title === "" || mb_strlen($title) > 200) {
            throw InvariantViolation::because("Chapter title must be 1-200 characters");
        }

        $this->title = $title;
    }

    public function setImage(string $image): void
    {
        if (mb_strlen($image) > 2000) {
            throw InvariantViolation::because("Chapter image is too long");
        }

        $this->image = $image;
    }

    public function holds(LevelId $levelId): bool
    {
        foreach ($this->nodes as $node) {
            if ($node->levelId->equals($levelId)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<LevelId> */
    public function levelIds(): array
    {
        return array_map(static fn (MapNode $n): LevelId => $n->levelId, $this->nodes);
    }

    /** @return list<NodeId> */
    public function nodeIds(): array
    {
        return array_map(static fn (MapNode $n): NodeId => $n->id, $this->nodes);
    }

    public function pin(MapNode $node): void
    {
        foreach ($this->nodes as $existing) {
            if ($existing->id->equals($node->id)) {
                throw InvariantViolation::because(
                    sprintf("Point %s is already on this chapter map", $node->id->value),
                );
            }
        }

        $this->nodes[] = $node;
    }

    /**
     * Replacing the whole map in one call matches how the editor works: you drag
     * several nodes, redraw a path, and save once. Validating the result as a
     * whole is also the only way to catch a half-applied change.
     *
     * @param list<MapNode> $nodes
     */
    public function replaceMap(array $nodes): void
    {
        $before = $this->nodes;
        $this->nodes = array_values($nodes);

        try {
            $this->assertGraphIsSound();
        } catch (InvariantViolation $e) {
            $this->nodes = $before;

            throw $e;
        }
    }

    /**
     * Dropping a level takes its paths with it. Leaving an edge pointing at a
     * level that is no longer on the map would show the player a road to nowhere
     * and, worse, would gate the levels behind it forever.
     */
    /**
     * Заменить одну точку, не трогая остальные.
     *
     * Соседний replaceMap() меняет набор целиком и потому спорит с любой другой
     * правкой этой главы — даже если руки заняты разными точками. Здесь
     * правится ровно то, что назвали.
     */
    public function replaceNode(MapNode $node): void
    {
        foreach ($this->nodes as $i => $existing) {
            if ($existing->id->value === $node->id->value) {
                $this->nodes[$i] = $node;

                return;
            }
        }

        throw InvariantViolation::because(sprintf(
            "Chapter %s has no point %s",
            $this->id->value,
            $node->id->value,
        ));
    }

    public function node(NodeId $id): ?MapNode
    {
        foreach ($this->nodes as $node) {
            if ($node->id->value === $id->value) {
                return $node;
            }
        }

        return null;
    }

    public function unpin(LevelId $levelId): void
    {
        $gone = [];

        foreach ($this->nodes as $node) {
            if ($node->levelId->equals($levelId)) {
                $gone[] = $node->id;
            }
        }

        $this->nodes = array_values(array_filter(
            $this->nodes,
            static fn (MapNode $n): bool => !$n->levelId->equals($levelId),
        ));

        $this->forgetLinksTo(...$gone);
    }

    /**
     * Called when points elsewhere are gone.
     *
     * A link to a point that no longer exists is worse than no link: on the map
     * it still looks like a way forward, and everything behind it stays shut
     * for good because the point that was meant to open it can never be
     * finished.
     */
    public function forgetLinksTo(NodeId ...$gone): void
    {
        if ($gone === []) {
            return;
        }

        $this->nodes = array_map(
            static fn (MapNode $n): MapNode => $n->withoutLinksTo(...$gone),
            $this->nodes,
        );
    }

    /** @param list<AssetId> $hot */
    public function setHot(array $hot): void
    {
        $this->hot = array_values($hot);
    }

    private function assertGraphIsSound(): void
    {
        // Ids must be unique; levels no longer have to be. A level shown at
        // two points is the whole point of points having names.
        $seen = [];

        foreach ($this->nodes as $node) {
            if (isset($seen[$node->id->value])) {
                throw InvariantViolation::because(
                    sprintf("Point %s appears twice on the chapter map", $node->id->value),
                );
            }

            $seen[$node->id->value] = true;
        }

    }

    /**
     * Matches chapterHash() on the client: the picture and the title are left
     * out, the paths are not. Paths decide which branches exist at all, so they
     * change what the chapter means; a new background does not.
     *
     * @param array<string, string> $levelHashes level id => level content hash
     *
     * @return array<string, mixed>
     */
    /**
     * Matches chapterHash() on the client.
     *
     * Level NAMES are counted here, not in the level's own fingerprint, and the
     * placement is the whole point. A name is not a property of the puzzle — it
     * is how the chapter introduces it — so renaming a level must not change
     * what the level IS, or every record ever set on it would be invalidated by
     * a typo fix. But the rename is still a real change to the chapter, and it
     * has to be publishable and versioned somewhere. Here.
     *
     * The chapter's own title is deliberately absent for the same reason one
     * level up: renaming a chapter is a change to the story, and it is counted
     * in the story's fingerprint instead.
     *
     * The picture is not counted either, at any level. A new background changes
     * how a chapter looks, not what it is to play.
     *
     * @param array<string, string> $levelHashes level id => level content hash
     * @param array<string, string> $levelNames  level id => level name
     *
     * @return array<string, mixed>
     */
    public function setMap(string $map): void
    {
        $this->map = $map;
    }

    public function map(): string
    {
        return $this->map;
    }

    public function canvas(): CanvasRect
    {
        return $this->canvas ??= new CanvasRect(0, 0, 420, 300);
    }

    public function placeOnCanvas(CanvasRect $rect): void
    {
        $this->canvas = $rect;
    }

    public function hashableContent(array $levelHashes, array $levelNames = []): array
    {
        $levels = array_map(
            static fn (MapNode $n): string => $n->levelId->value
                . ':' . ($levelHashes[$n->levelId->value] ?? 'null')
                . ':' . ($levelNames[$n->levelId->value] ?? ''),
            $this->nodes,
        );
        $links = [];

        foreach ($this->nodes as $node) {
            foreach ($node->next as $child) {
                $links[] = $node->id->value . ">" . $child->value;
            }
        }

        sort($levels, SORT_STRING);
        sort($links, SORT_STRING);

        return ["id" => $this->id->value, "levels" => $levels, "links" => $links];
    }
}
