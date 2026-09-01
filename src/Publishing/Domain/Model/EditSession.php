<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Model;

use DateTimeImmutable;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Domain\ValueObject\EditSessionId;
use Wob\Publishing\Domain\ValueObject\ReleaseId;

/**
 * "I am editing someone else's release" — before anything has been changed.
 *
 * This is the copy-on-write, spelled out as an object instead of left implicit
 * in a controller. Opening someone else's release for editing creates one of
 * these and nothing else: no fork Story exists yet, no rows for its chapters or
 * levels, nothing a NotFound could ever stumble on. It is a pointer that says
 * "if a change happens, it happens relative to this".
 *
 * The fork is born on the first write — see ForkOnFirstEdit — at which point
 * this session's forkStoryId stops being null. Everything after that is
 * ordinary Library editing against a real, if still mostly-empty, Story.
 *
 * One session per (editor, base release): reopening the same release you are
 * already mid-edit on resumes the same fork rather than starting a second one,
 * which is what the repository's find-or-create enforces.
 */
final class EditSession
{
    private ?StoryId $forkStoryId = null;

    public function __construct(
        public readonly EditSessionId $id,
        public readonly OwnerId $editorId,
        public readonly ReleaseId $baseReleaseId,
        public readonly StoryId $baseStoryId,
        public readonly DateTimeImmutable $startedAt,
    ) {
    }

    public static function reconstitute(
        EditSessionId $id,
        OwnerId $editorId,
        ReleaseId $baseReleaseId,
        StoryId $baseStoryId,
        DateTimeImmutable $startedAt,
        ?StoryId $forkStoryId,
    ): self {
        $session = new self($id, $editorId, $baseReleaseId, $baseStoryId, $startedAt);
        $session->forkStoryId = $forkStoryId;

        return $session;
    }

    public function hasForked(): bool
    {
        return $this->forkStoryId !== null;
    }

    public function forkStoryId(): ?StoryId
    {
        return $this->forkStoryId;
    }

    /** Called exactly once, by the use case that just created the fork Story. */
    public function markForked(StoryId $forkStoryId): void
    {
        $this->forkStoryId ??= $forkStoryId;
    }
}
