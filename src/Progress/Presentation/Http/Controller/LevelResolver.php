<?php

declare(strict_types=1);

namespace Wob\Progress\Presentation\Http\Controller;

use Illuminate\Database\ConnectionInterface;

/**
 * Public level id to internal UUID.
 *
 * Progress deliberately does not import the Library repository to do this: it
 * needs one identifier, not a story with its chapters, and depending on
 * Library aggregate would tie the two contexts together over nothing. A narrow
 * lookup against a stable pair of columns is the smaller commitment.
 */
final readonly class LevelResolver
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function internalId(string $storyPublicId, string $levelPublicId): ?string
    {
        $row = $this->db->table("levels")
            ->join("stories", "stories.id", "=", "levels.story_id")
            ->where("stories.public_id", $storyPublicId)
            ->where("levels.public_id", $levelPublicId)
            ->select("levels.id")
            ->first();

        return $row?->id;
    }
}
