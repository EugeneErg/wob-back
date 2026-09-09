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
    public function ownedBy(OwnerId $ownerId, bool $withRetired = false): array;

    public function save(Asset $asset): void;

    /** Anyone's asset, by id alone: levels may name assets they do not own. */
    public function byId(AssetId $id): ?Asset;
}
