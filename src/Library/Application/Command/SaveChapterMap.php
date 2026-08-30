<?php

declare(strict_types=1);

namespace Wob\Library\Application\Command;

/**
 * The chapter map, replaced whole: node positions, exits and paths in one go.
 * Dragging a node and drawing a path are the same gesture to the author, and
 * splitting them into two requests would let the map be saved half-moved.
 *
 */
final readonly class SaveChapterMap
{
    /**
     * @param list<array{levelId: string, x: float|int, y: float|int, next?: ?string}> $nodes
     * @param list<array{from: string, to: string}>                                    $edges
     */
    public function __construct(
        public string $ownerId,
        public string $storyId,
        public string $chapterId,
        public ?string $title,
        public ?string $image,
        public array $nodes,
        public array $edges,
        public int $expectedVersion,
    ) {
    }
}
