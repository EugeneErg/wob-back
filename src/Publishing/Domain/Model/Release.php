<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Model;

use DateTimeImmutable;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Domain\ValueObject\ContentSnapshot;
use Wob\Publishing\Domain\ValueObject\ReleaseId;
use Wob\Shared\Domain\AggregateRoot;
use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * A release: the author's draft, frozen at a moment in time.
 *
 * Only releases are voted on, ranked, or raced. A draft changes under its
 * players' feet — the author might be mid-edit on the very level someone is
 * playing — so nothing about a draft is fair to attach a permanent number to.
 * Publishing IS the act of promising "this will not change again", and this
 * class is where that promise is kept: its content snapshot is set once, in
 * the constructor, and nothing in this class ever touches it again.
 *
 * A release does not vote itself into anything. Whether it is playable by
 * strangers, whether it holds the canon crown — those are facts ABOUT a
 * release, decided by other services from evidence (the author's own
 * completion, a vote tally) that lives outside it. Baking "am I canonical"
 * into this class would mean the release deciding its own fate from data it
 * cannot see without reaching outside itself, which is exactly what an
 * aggregate must not do.
 */
final class Release extends AggregateRoot
{
    private ?DateTimeImmutable $authorClearedAt = null;

    private function __construct(
        public readonly ReleaseId $id,
        public readonly StoryId $storyId,
        public readonly int $number,
        public readonly ContentSnapshot $content,
        public readonly string $contentHash,
        public readonly ?ReleaseId $previousReleaseId,
        public readonly DateTimeImmutable $releasedAt,
    ) {
        if ($number < 1) {
            throw InvariantViolation::because('Release numbers start at 1');
        }
    }

    /**
     * The number is supplied by the caller rather than computed here, because
     * "the next number" requires knowing every prior release of the story —
     * information a repository has and a soon-to-exist aggregate does not.
     */
    public static function cut(
        StoryId $storyId,
        int $number,
        ContentSnapshot $content,
        string $contentHash,
        ?ReleaseId $previousReleaseId,
        DateTimeImmutable $now,
    ): self {
        return new self(ReleaseId::next(), $storyId, $number, $content, $contentHash, $previousReleaseId, $now);
    }

    public static function reconstitute(
        ReleaseId $id,
        StoryId $storyId,
        int $number,
        ContentSnapshot $content,
        string $contentHash,
        ?ReleaseId $previousReleaseId,
        DateTimeImmutable $releasedAt,
        ?DateTimeImmutable $authorClearedAt,
    ): self {
        $release = new self($id, $storyId, $number, $content, $contentHash, $previousReleaseId, $releasedAt);
        $release->authorClearedAt = $authorClearedAt;

        return $release;
    }

    public function isClearedByAuthor(): bool
    {
        return $this->authorClearedAt !== null;
    }

    public function authorClearedAt(): ?DateTimeImmutable
    {
        return $this->authorClearedAt;
    }

    /**
     * The author finished their own release. Until this happens nobody else
     * may play it: a story its own creator cannot complete is not ready for
     * strangers, and this is the one gate simple enough to enforce as a hard
     * rule rather than a matter of taste.
     */
    public function clearedByAuthor(DateTimeImmutable $at): void
    {
        $this->authorClearedAt ??= $at;
    }
}
