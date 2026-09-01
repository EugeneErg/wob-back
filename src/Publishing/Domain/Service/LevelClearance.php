<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Service;

use Wob\Publishing\Domain\ValueObject\ReleaseId;

/**
 * Has this player finished this level, in this release?
 *
 * The right to vote hangs on it, so it is named as its own idea rather than
 * left as an ad-hoc query inside the vote handler. It is also the one question
 * that has to be asked about a specific release: finishing a level in version 2
 * says nothing about version 5, where that level may be a different puzzle
 * entirely.
 */
interface LevelClearance
{
    public function hasCleared(ReleaseId $releaseId, string $levelPublicId, string $playerId): bool;
}
