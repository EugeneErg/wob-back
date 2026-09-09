<?php

declare(strict_types=1);

namespace Wob\Library\Infrastructure\Persistence\Database;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Ramsey\Uuid\Uuid;
use Wob\Library\Domain\Model\Asset;
use Wob\Library\Domain\Repository\AssetRepository;
use Wob\Library\Domain\ValueObject\AssetId;
use Wob\Library\Domain\ValueObject\EntityPlacement;
use Wob\Library\Domain\ValueObject\OwnerId;

final readonly class DatabaseAssetRepository implements AssetRepository
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function find(AssetId $id, OwnerId $ownerId): ?Asset
    {
        $row = $this->db->table("assets")
            ->where("public_id", $id->value)
            ->where("owner_id", $ownerId->value)
            ->first();

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Anyone's asset, by id alone.
     *
     * Assets may be used by authors who do not own them, so resolving the
     * reference a level makes cannot be scoped to the owner. find() stays
     * owner-scoped because it guards writes; this one only reads.
     */
    public function byId(AssetId $id): ?Asset
    {
        $row = $this->db->table("assets")->where("public_id", $id->value)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * The author's shelf.
     *
     * Retired assets are left out: they exist so that levels already built on
     * them keep working, not so that anyone picks them again.
     */
    public function ownedBy(OwnerId $ownerId, bool $withRetired = false): array
    {
        $q = $this->db->table("assets")->where("owner_id", $ownerId->value);

        if (!$withRetired) {
            $q->whereNull("retired_at");
        }

        return $q->orderBy("created_at")->get()->map($this->hydrate(...))->all();
    }

    public function save(Asset $asset): void
    {
        $values = [
            "owner_id" => $asset->ownerId->value,
            "public_id" => $asset->id->value,
            "title" => $asset->title(),
            "entities" => json_encode($asset->entities(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            "retired_at" => $asset->retiredAt(),
            "updated_at" => now(),
        ];

        // "entities" and "title" are in the update list only so that a repeated
        // save of the same asset is harmless; nothing in the application edits
        // them, and the route that used to has been removed.
        $this->db->table("assets")->upsert(
            [["id" => Uuid::uuid4()->toString(), ...$values, "created_at" => now()]],
            ["owner_id", "public_id"],
            ["title", "entities", "retired_at", "updated_at"],
        );
    }

    private function hydrate(object $row): Asset
    {
        return new Asset(
            new AssetId($row->public_id),
            new OwnerId($row->owner_id),
            $row->title,
            array_map(
                EntityPlacement::fromObject(...),
                json_decode($row->entities, false, 512, JSON_THROW_ON_ERROR),
            ),
            $row->retired_at === null ? null : new DateTimeImmutable($row->retired_at),
        );
    }
}
