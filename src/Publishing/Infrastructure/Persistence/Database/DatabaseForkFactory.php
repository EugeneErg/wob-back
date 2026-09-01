<?php

declare(strict_types=1);

namespace Wob\Publishing\Infrastructure\Persistence\Database;

use Illuminate\Database\ConnectionInterface;
use stdClass;
use Wob\Library\Domain\Model\Chapter;
use Wob\Library\Domain\Model\Level;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\Repository\StoryRepository;
use Wob\Library\Domain\Service\IdGenerator;
use Wob\Library\Domain\ValueObject\ChapterId;
use Wob\Library\Domain\ValueObject\Dimensions;
use Wob\Library\Domain\ValueObject\EntityPlacement;
use Wob\Library\Domain\ValueObject\Gravity;
use Wob\Library\Domain\ValueObject\LevelId;
use Wob\Library\Domain\ValueObject\MapEdge;
use Wob\Library\Domain\ValueObject\MapNode;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Domain\Model\Release;
use Wob\Publishing\Domain\Repository\ForkOverrideRepository;
use Wob\Publishing\Domain\Service\ForkFactory;
use Wob\Publishing\Domain\ValueObject\ContentSnapshot;

/**
 * Creates the Story rows a fork or an accepted proposal needs.
 *
 * Two very different jobs behind one interface, and the difference is the
 * point.
 *
 * A fork starts empty: a Story with a title and a line home, and no chapters or
 * levels at all, because everything is still read from the base release. That
 * is what keeps forking a fifty-level story to fix a typo cheap.
 *
 * An accepted proposal is the opposite: a complete, self-contained draft
 * holding the original content with the contributor's changes folded in. It has
 * to be complete, because the author will edit and publish from it, and a draft
 * that silently reads half its content from somebody else's release is a draft
 * that changes when they publish.
 */
final readonly class DatabaseForkFactory implements ForkFactory
{
    public function __construct(
        private StoryRepository $stories,
        private ForkOverrideRepository $overrides,
        private IdGenerator $ids,
        private ConnectionInterface $db,
    ) {
    }

    public function create(OwnerId $editorId, Release $base): StoryId
    {
        $original = $this->db->table('stories')->where('public_id', $base->storyId->value)->first();
        $forkId = new StoryId($this->ids->next('story'));

        // Deliberately empty. Chapters and levels appear only as the editor
        // touches them; until then the base release answers every question.
        $this->stories->save(new Story(
            $forkId,
            $editorId,
            $this->fitTitle(($original->title ?? 'Story') . ' (fork)'),
            $original->cover ?? '#1a2b33',
        ));

        $this->db->table('stories')->where('public_id', $forkId->value)->update([
            'forked_from_story_id' => $original->id ?? null,
            'forked_from_release_id' => $base->id->value,
            'updated_at' => now(),
        ]);

        return $forkId;
    }

    public function draftFromAccepted(OwnerId $authorId, Release $base, StoryId $forkId): StoryId
    {
        $flattened = $this->overrides->overlayFor($forkId, $base->content)->flatten();
        $draftId = new StoryId($this->ids->next('story'));
        $original = $this->db->table('stories')->where('public_id', $base->storyId->value)->first();

        $this->stories->save($this->assemble(
            $draftId,
            $authorId,
            $this->fitTitle(($original->title ?? 'Story') . ' (proposed)'),
            $original->cover ?? '#1a2b33',
            $flattened,
        ));

        $this->db->table('stories')->where('public_id', $draftId->value)->update([
            'forked_from_story_id' => $original->id ?? null,
            'forked_from_release_id' => $base->id->value,
            'updated_at' => now(),
        ]);

        return $draftId;
    }

    private function assemble(
        StoryId $id,
        OwnerId $owner,
        string $title,
        string $cover,
        ContentSnapshot $content,
    ): Story {
        $levels = array_map(
            static fn (stdClass $l): Level => new Level(
                new LevelId($l->id),
                (string) ($l->name ?? 'Level'),
                new Dimensions((int) $l->width, (int) $l->height),
                Gravity::fromArray((array) ($l->gravity ?? new stdClass())),
                (int) ($l->goal ?? 0),
                array_map(EntityPlacement::fromObject(...), $l->entities ?? []),
            ),
            $content->levels,
        );

        $chapters = array_map(
            static fn (stdClass $c): Chapter => new Chapter(
                new ChapterId($c->id),
                (string) ($c->title ?? 'Chapter'),
                (string) ($c->image ?? ''),
                array_map(
                    static fn (stdClass $n): MapNode => new MapNode(
                        new LevelId($n->levelId),
                        (float) $n->x,
                        (float) $n->y,
                        isset($n->next) ? new ChapterId($n->next) : null,
                    ),
                    $c->nodes ?? [],
                ),
                array_map(
                    static fn (stdClass $e): MapEdge => new MapEdge(new LevelId($e->from), new LevelId($e->to)),
                    $c->edges ?? [],
                ),
            ),
            $content->chapters,
        );

        return new Story($id, $owner, $title, $cover, $chapters, $levels);
    }

    /** Story titles cap at 200 characters, and a suffix must not push one over. */
    private function fitTitle(string $title): string
    {
        return mb_strlen($title) > 200 ? mb_substr($title, 0, 200) : $title;
    }
}
