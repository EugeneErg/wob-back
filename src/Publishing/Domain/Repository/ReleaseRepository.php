<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Repository;

use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Domain\Model\Release;
use Wob\Publishing\Domain\ValueObject\ReleaseId;

interface ReleaseRepository
{
    /** @throws \Wob\Shared\Domain\Exception\NotFound */
    public function get(ReleaseId $id): Release;

    public function find(ReleaseId $id): ?Release;

    /**
     * Every release of a story, newest first. Authors need the history and the
     * carry-over needs the predecessor, so there is no "latest only" variant
     * that would tempt callers into pretending the rest do not exist.
     *
     * @return list<Release>
     */
    public function ofStory(StoryId $storyId): array;

    public function latestOf(StoryId $storyId): ?Release;

    /** What number the next release of this story gets. */
    public function nextNumberFor(StoryId $storyId): int;

    public function save(Release $release): void;

    /**
     * Content-addressed lookup, which is how a recording resolves the exact
     * bytes it was played against — a hash names one version and can never
     * name a different one later.
     */
    public function findByContentHash(string $hash): ?Release;
}
