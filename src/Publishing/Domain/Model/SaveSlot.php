<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Model;

use DateTimeImmutable;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Domain\ValueObject\ReleaseId;
use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * One run through one story.
 *
 * Slots hang off a story rather than off the player, and that is the whole
 * design: each story is its own game here, so someone replaying a favourite
 * from the start should not have to give up their place in something else. A
 * console-style global slot would force exactly that trade.
 *
 * A slot remembers the release it was started on and stays there. Swapping the
 * content under a run in progress would move the goalposts mid-journey — levels
 * appearing, disappearing or changing shape between one evening and the next —
 * and would quietly invalidate the times already set in it. Moving to a newer
 * version is a thing the player chooses, not something that happens to them.
 */
final class SaveSlot
{
    public const MAX_PER_STORY = 3;

    private function __construct(
        public readonly string $id,
        public readonly string $playerId,
        public readonly StoryId $storyId,
        public readonly int $number,
        private ?string $label,
        private ?ReleaseId $releaseId,
        private ?DateTimeImmutable $lastPlayedAt,
    ) {
        if ($number < 1 || $number > self::MAX_PER_STORY) {
            throw InvariantViolation::because(
                sprintf('A story has slots 1 to %d', self::MAX_PER_STORY),
            );
        }
    }

    public static function start(
        string $id,
        string $playerId,
        StoryId $storyId,
        int $number,
        ReleaseId $releaseId,
        DateTimeImmutable $now,
    ): self {
        return new self($id, $playerId, $storyId, $number, null, $releaseId, $now);
    }

    public static function reconstitute(
        string $id,
        string $playerId,
        StoryId $storyId,
        int $number,
        ?string $label,
        ?ReleaseId $releaseId,
        ?DateTimeImmutable $lastPlayedAt,
    ): self {
        return new self($id, $playerId, $storyId, $number, $label, $releaseId, $lastPlayedAt);
    }

    public function label(): ?string
    {
        return $this->label;
    }

    public function releaseId(): ?ReleaseId
    {
        return $this->releaseId;
    }

    public function lastPlayedAt(): ?DateTimeImmutable
    {
        return $this->lastPlayedAt;
    }

    public function rename(?string $label): void
    {
        if ($label !== null && mb_strlen(trim($label)) > 60) {
            throw InvariantViolation::because('A slot label is at most 60 characters');
        }

        $this->label = $label === null ? null : trim($label);
    }

    public function touched(DateTimeImmutable $at): void
    {
        $this->lastPlayedAt = $at;
    }

    /**
     * Move this run onto a newer version of the story.
     *
     * Deliberately explicit. The player is told what it costs — progress is
     * kept, but the levels may not be the ones they finished — and chooses. The
     * alternative, following the story's newest release automatically, would
     * change what someone is playing without them ever asking.
     */
    public function moveTo(ReleaseId $releaseId): void
    {
        $this->releaseId = $releaseId;
    }
}
