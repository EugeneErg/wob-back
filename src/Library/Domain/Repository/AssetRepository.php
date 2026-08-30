<?php

declare(strict_types=1);

namespace Wob\Library\Domain\Repository;

use Wob\Library\Domain\Model\Asset;
use Wob\Library\Domain\ValueObject\AssetId;
use Wob\Library\Domain\ValueObject\OwnerId;

interface AssetRepository
{
    public function find(AssetId $id, OwnerId $ownerId): ?Asset;

    /** @return list<Asset> */
    public function ownedBy(OwnerId $ownerId): array;

    public function save(Asset $asset): void;

    public function remove(Asset $asset): void;
}
