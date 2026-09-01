<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Repository;

use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Publishing\Domain\Model\EditSession;
use Wob\Publishing\Domain\ValueObject\ReleaseId;

interface EditSessionRepository
{
    /**
     * The session this editor already has against this release, if any.
     *
     * Find-or-create rather than always-create: reopening a release you are
     * already part way through editing has to resume that work, not start a
     * second fork beside it.
     */
    public function forEditor(OwnerId $editorId, ReleaseId $baseReleaseId): ?EditSession;

    public function save(EditSession $session): void;
}
