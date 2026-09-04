<?php

declare(strict_types=1);

namespace Wob\Media\Domain\Repository;

use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Media\Domain\Model\Media;
use Wob\Media\Domain\ValueObject\MediaId;

interface MediaRepository
{
    public function save(Media $media): void;

    public function find(MediaId $id): ?Media;

    /** @return list<Media> newest first */
    public function ownedBy(OwnerId $owner): array;
}
