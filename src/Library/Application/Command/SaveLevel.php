<?php

declare(strict_types=1);

namespace Wob\Library\Application\Command;

use stdClass;

/**
 * The editor "save" — the level contents, replaced wholesale.
 *
 * Wholesale rather than a patch of individual entities, because that is what
 * the editor actually produces: it holds the level in memory, the player drags
 * things around, and one coherent picture comes out. A per-entity API would
 * invent an ordering problem that does not exist and would let a level be
 * half-saved.
 *
 */
final readonly class SaveLevel
{
    /**
     * @param list<stdClass> $entities raw entity envelopes, contents untouched
     * @param list<string> $hot
     */
    public function __construct(
        public string $ownerId,
        public string $storyId,
        public string $levelId,
        public string $name,
        public int $width,
        public int $height,
        public float $gravityX,
        public float $gravityY,
        public int $goal,
        public array $entities,
        public array $hot,
        public ?string $image = null,
    ) {
    }
}
