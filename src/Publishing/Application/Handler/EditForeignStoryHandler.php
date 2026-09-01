<?php

declare(strict_types=1);

namespace Wob\Publishing\Application\Handler;

use Illuminate\Database\ConnectionInterface;
use stdClass;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Application\Command\EditForeignStory;
use Wob\Publishing\Domain\Model\EditSession;
use Wob\Publishing\Domain\Repository\EditSessionRepository;
use Wob\Publishing\Domain\Repository\ForkOverrideRepository;
use Wob\Publishing\Domain\Repository\ReleaseRepository;
use Wob\Publishing\Domain\Service\ForkFactory;
use Wob\Publishing\Domain\ValueObject\EditSessionId;
use Wob\Publishing\Domain\ValueObject\ReleaseId;
use Wob\Shared\Domain\Clock;

/**
 * Change something in someone else's released story.
 *
 * This is the copy-on-write in one method. Opening a release to look around
 * creates a session and nothing else — no fork story, no rows, nothing a stray
 * query could stumble on. The fork is born here, on the first actual change,
 * and only the changed piece is written: edit a level's contents and the fork
 * holds that one level, with its chapter still read from the base because
 * nothing in it moved.
 *
 * Ids do not change on copy. A public id names a level, not a row, so the
 * fork's version of `lvl-tower` is still `lvl-tower` — which is exactly what
 * lets an uncopied chapter go on pointing at ids that resolve to a mixture of
 * the fork's versions and the base's.
 */
final readonly class EditForeignStoryHandler
{
    public function __construct(
        private EditSessionRepository $sessions,
        private ForkOverrideRepository $overrides,
        private ReleaseRepository $releases,
        private ForkFactory $forks,
        private Clock $clock,
        private ConnectionInterface $db,
    ) {
    }

    public function __invoke(EditForeignStory $command): StoryId
    {
        $editor = new OwnerId($command->editorId);
        $baseRelease = new ReleaseId($command->baseReleaseId);

        return $this->db->transaction(function () use ($command, $editor, $baseRelease): StoryId {
            $release = $this->releases->get($baseRelease);

            $session = $this->sessions->forEditor($editor, $baseRelease) ?? new EditSession(
                EditSessionId::next(),
                $editor,
                $baseRelease,
                $release->storyId,
                $this->clock->now(),
            );

            // The moment of the write. Before this, nothing existed.
            if (!$session->hasForked()) {
                $session->markForked($this->forks->create($editor, $release));
            }

            $this->sessions->save($session);

            $fork = $session->forkStoryId();

            // Only what was touched. The kind decides what a change means:
            // rewriting a level's contents copies the level; moving nodes
            // around a map copies the chapter, and nothing else moves with it.
            if ($command->content === null) {
                $this->overrides->remove($fork, $command->kind, $command->publicId);
            } else {
                $this->overrides->put($fork, $command->kind, $command->publicId, $command->content);
            }

            return $fork;
        });
    }
}
