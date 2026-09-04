<?php

declare(strict_types=1);

namespace Wob\Publishing\Application\Handler;

use Illuminate\Database\ConnectionInterface;
use stdClass;
use Wob\Library\Domain\Repository\StoryRepository;
use Wob\Library\Domain\Service\ContentHasher;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Application\Command\PublishRelease;
use Wob\Publishing\Domain\Model\Release;
use Wob\Publishing\Domain\Repository\ReleaseRepository;
use Wob\Publishing\Domain\Repository\VoteRepository;
use Wob\Achievements\Application\Handler\GrantAwards;
use Wob\Publishing\Domain\Service\VoteCarryOver;
use Wob\Publishing\Domain\ValueObject\ContentSnapshot;
use Wob\Shared\Domain\Clock;
use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * Freeze the author's draft into a release.
 *
 * Two things happen here that cannot happen anywhere else. The content is
 * copied, not referenced — from this moment the release means exactly these
 * bytes forever, which is what makes a vote or a record attached to it mean
 * anything. And the previous release's votes are carried forward in proportion
 * to how much each level actually changed, so that fixing a typo does not cost
 * an author the standing of a level a hundred people liked, while rebuilding a
 * level from scratch does not let it inherit a reputation it never earned.
 *
 * Publishing does not make the release playable by others. That needs the
 * author to finish it themselves first — a separate act, recorded separately.
 */
final readonly class PublishReleaseHandler
{
    public function __construct(
        private StoryRepository $stories,
        private ReleaseRepository $releases,
        private VoteRepository $votes,
        private ContentHasher $hasher,
        private VoteCarryOver $carryOver,
        private GrantAwards $awards,
        private Clock $clock,
        private ConnectionInterface $db,
    ) {
    }

    public function __invoke(PublishRelease $command): Release
    {
        $owner = new OwnerId($command->ownerId);
        $storyId = new StoryId($command->storyId);

        return $this->db->transaction(function () use ($owner, $storyId): Release {
            $story = $this->stories->get($owner, $storyId);
            $story->assertOwnedBy($owner);

            if ($story->chapters() === []) {
                throw InvariantViolation::because('A story with no chapters has nothing to release');
            }

            $previous = $this->releases->latestOf($storyId);
            $content = $this->snapshot($story);
            $hash = $story->contentHash($this->hasher)->value;

            // Nothing changed since the last release, so there is nothing to
            // publish. Cutting an identical release would split one version's
            // votes and records across two entries for no reason a player could
            // understand.
            //
            // Compared on the whole snapshot rather than on the content hash,
            // and the difference matters. The hash deliberately leaves names
            // out — renaming a level must not invalidate anybody's records —
            // but a release freezes names too, so "the hash is the same" and
            // "nothing changed" are not the same statement. Going by the hash
            // made a rename unpublishable.
            if ($previous !== null && $this->isUnchanged($previous->content, $content)) {
                throw InvariantViolation::because('Nothing has changed since the last release');
            }

            $release = Release::cut(
                $storyId,
                $this->releases->nextNumberFor($storyId),
                $content,
                $hash,
                $previous?->id,
                $this->clock->now(),
            );

            $this->releases->save($release);

            if ($previous !== null) {
                $this->carryVotesForward($previous, $release);
            }

            $this->awards->afterRelease($owner->value, $storyId->value);

            return $release;
        });
    }

    /**
     * Whether two snapshots describe the same release.
     *
     * Canonicalised through the hasher rather than compared with ==, so that
     * key order and float formatting cannot make two identical snapshots look
     * different — the same normalisation the content hash relies on, used here
     * for a different question.
     */
    private function isUnchanged(ContentSnapshot $before, ContentSnapshot $after): bool
    {
        return $this->hasher->canonicalise([
            'chapters' => $before->chapters,
            'levels' => $before->levels,
        ]) === $this->hasher->canonicalise([
            'chapters' => $after->chapters,
            'levels' => $after->levels,
        ]);
    }

    private function carryVotesForward(Release $previous, Release $current): void
    {
        $now = $this->clock->now();

        foreach ($current->content->levels as $level) {
            $before = $previous->content->level($level->id);

            // A level the previous release never had has no history to inherit.
            if ($before === null) {
                continue;
            }

            $old = $this->votes->forLevel($previous->id, $level->id);

            if ($old === []) {
                continue;
            }

            $this->votes->saveAll(
                $this->carryOver->apply($old, $before, $level, $current->id, $now),
            );
        }
    }

    /**
     * The story as flat lists, in the shape the client and the hasher already
     * speak — the same wire format as an exported bundle, so a release and a
     * file on disk describe content the same way.
     */
    private function snapshot(object $story): ContentSnapshot
    {
        $chapters = [];

        foreach ($story->chapters() as $chapter) {
            $entry = new stdClass();
            $entry->id = $chapter->id->value;
            $entry->title = $chapter->title();
            $entry->image = $chapter->image();
            $entry->nodes = array_map(static function ($node): stdClass {
                $out = new stdClass();
                $out->id = $node->id->value;
                $out->levelId = $node->levelId->value;
                $out->x = $node->x;
                $out->y = $node->y;

                foreach (['name' => $node->name, 'image' => $node->image, 'outro' => $node->outro] as $k => $v) {
                    if ($v !== '') {
                        $out->$k = $v;
                    }
                }

                $out->next = array_map(static fn ($c): string => $c->value, $node->next);

                return $out;
            }, $chapter->nodes());

            $chapters[] = $entry;
        }

        $levels = [];

        foreach ($story->levels() as $level) {
            $entry = new stdClass();
            $entry->id = $level->id->value;
            $entry->name = $level->name();
            $entry->width = $level->dimensions()->width;
            $entry->height = $level->dimensions()->height;
            $entry->gravity = (object) $level->gravity()->toArray();
            $entry->goal = $level->goal();
            $entry->entities = array_map(
                static fn ($entity): stdClass => $entity->jsonSerialize(),
                $level->entities(),
            );
            $entry->hash = $story->levelHash($this->hasher, $level)->value;

            $levels[] = $entry;
        }

        return new ContentSnapshot($chapters, $levels);
    }
}
