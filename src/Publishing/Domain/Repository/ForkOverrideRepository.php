<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Repository;

use stdClass;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Domain\Model\ForkOverlay;
use Wob\Publishing\Domain\ValueObject\ContentSnapshot;

/**
 * What a fork has actually changed.
 *
 * Only the touched pieces are stored — everything else is read from the base
 * release. That is the copy-on-write, and it is why forking a fifty-level story
 * to fix one typo costs one row rather than fifty.
 */
interface ForkOverrideRepository
{
    public function overlayFor(StoryId $forkId, ContentSnapshot $base): ForkOverlay;

    /** Record a changed level or chapter. Kind is 'level' or 'chapter'. */
    public function put(StoryId $forkId, string $kind, string $publicId, stdClass $content): void;

    /**
     * Record a deletion.
     *
     * A tombstone rather than removing the row, because absence already means
     * "not touched, go look at the base" — an unmarked delete would resurrect
     * on the next read.
     */
    public function remove(StoryId $forkId, string $kind, string $publicId): void;
}
