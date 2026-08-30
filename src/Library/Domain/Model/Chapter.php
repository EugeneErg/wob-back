<?php

declare(strict_types=1);

namespace Wob\Library\Domain\Model;

use Wob\Library\Domain\ValueObject\AssetId;
use Wob\Library\Domain\ValueObject\ChapterId;
use Wob\Library\Domain\ValueObject\LevelId;
use Wob\Library\Domain\ValueObject\MapEdge;
use Wob\Library\Domain\ValueObject\MapNode;
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
     * @param list<MapEdge> $edges
     * @param list<AssetId> $hot
     */
    public function __construct(
        public readonly ChapterId $id,
        private string $title,
        private string $image,
        private array $nodes = [],
        private array $edges = [],
        private array $hot = [],
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

    /** @return list<MapEdge> */
    public function edges(): array
    {
        return $this->edges;
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

    public function pin(MapNode $node): void
    {
        if ($this->holds($node->levelId)) {
            throw InvariantViolation::because(
                sprintf("Level %s is already on this chapter map", $node->levelId->value),
            );
        }

        $this->nodes[] = $node;
    }

    /**
     * Replacing the whole map in one call matches how the editor works: you drag
     * several nodes, redraw a path, and save once. Validating the result as a
     * whole is also the only way to catch a half-applied change.
     *
     * @param list<MapNode> $nodes
     * @param list<MapEdge> $edges
     */
    public function replaceMap(array $nodes, array $edges): void
    {
        $before = [$this->nodes, $this->edges];
        $this->nodes = array_values($nodes);
        $this->edges = array_values($edges);

        try {
            $this->assertGraphIsSound();
        } catch (InvariantViolation $e) {
            [$this->nodes, $this->edges] = $before;

            throw $e;
        }
    }

    /**
     * Dropping a level takes its paths with it. Leaving an edge pointing at a
     * level that is no longer on the map would show the player a road to nowhere
     * and, worse, would gate the levels behind it forever.
     */
    public function unpin(LevelId $levelId): void
    {
        $this->nodes = array_values(array_filter(
            $this->nodes,
            static fn (MapNode $n): bool => !$n->levelId->equals($levelId),
        ));
        $this->edges = array_values(array_filter(
            $this->edges,
            static fn (MapEdge $e): bool => !$e->from->equals($levelId) && !$e->to->equals($levelId),
        ));
    }

    /** Called when the chapter this map pointed out to is gone. */
    public function forgetExitsTo(ChapterId $chapterId): void
    {
        $this->nodes = array_map(
            static fn (MapNode $n): MapNode => $n->next?->equals($chapterId) === true ? $n->withNext(null) : $n,
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
        $seen = [];

        foreach ($this->nodes as $node) {
            if (isset($seen[$node->levelId->value])) {
                throw InvariantViolation::because(
                    sprintf("Level %s appears twice on the chapter map", $node->levelId->value),
                );
            }

            $seen[$node->levelId->value] = true;
        }

        foreach ($this->edges as $edge) {
            foreach ([$edge->from, $edge->to] as $end) {
                if (!isset($seen[$end->value])) {
                    throw InvariantViolation::because(
                        sprintf("A path points at level %s, which is not on this chapter map", $end->value),
                    );
                }
            }
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
    public function hashableContent(array $levelHashes): array
    {
        $levels = array_map(
            static fn (MapNode $n): string => $n->levelId->value . ":" . ($levelHashes[$n->levelId->value] ?? "null"),
            $this->nodes,
        );
        $edges = array_map(static fn (MapEdge $e): string => $e->from->value . ">" . $e->to->value, $this->edges);

        sort($levels, SORT_STRING);
        sort($edges, SORT_STRING);

        return ["id" => $this->id->value, "levels" => $levels, "edges" => $edges];
    }
}
