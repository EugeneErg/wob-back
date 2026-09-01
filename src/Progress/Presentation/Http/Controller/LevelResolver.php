<?php

declare(strict_types=1);

namespace Wob\Progress\Presentation\Http\Controller;

use Illuminate\Database\ConnectionInterface;

/**
 * Does this story contain that level?
 *
 * Progress deliberately does not import the Library repository to ask: it needs
 * one yes or no, not a story with its chapters, and depending on Library's
 * aggregate would tie the two contexts together over nothing.
 *
 * It used to resolve a public id to a row id, and that resolution was how
 * progress ended up hostage to the author's draft — a deleted row took with it
 * the progress of everyone playing a frozen release the level is still in.
 */
final readonly class LevelResolver
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    /**
     * Checked against the draft and against every release of the story.
     *
     * Both, because a player is usually playing a frozen release while the
     * author edits the draft around it. Checking only the draft would refuse
     * progress on a level the player can plainly see on their screen.
     */
    public function existsIn(string $storyPublicId, string $levelPublicId): bool
    {
        $inDraft = $this->db->table('levels')
            ->join('stories', 'stories.id', '=', 'levels.story_id')
            ->where('stories.public_id', $storyPublicId)
            ->where('levels.public_id', $levelPublicId)
            ->exists();

        if ($inDraft) {
            return true;
        }

        return $this->db->table('releases')
            ->join('stories', 'stories.id', '=', 'releases.story_id')
            ->where('stories.public_id', $storyPublicId)
            ->whereRaw('releases.content::text LIKE ?', ['%"' . $levelPublicId . '"%'])
            ->exists();
    }
}
