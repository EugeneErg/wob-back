<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Service;

use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Domain\Model\Release;

/**
 * Brings a fork into existence.
 *
 * A service rather than a constructor, because creating a fork means writing a
 * Story row that belongs to Library — and Publishing must not reach into
 * Library's aggregate to do it. What comes back is only the id.
 */
interface ForkFactory
{
    public function create(OwnerId $editorId, Release $base): StoryId;

    /**
     * A fresh draft for the author of the original, holding their story with
     * someone else's accepted changes applied.
     */
    public function draftFromAccepted(OwnerId $authorId, Release $base, StoryId $forkId): StoryId;
}
