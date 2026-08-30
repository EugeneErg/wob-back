<?php

declare(strict_types=1);

namespace Wob\Library\Infrastructure\Persistence\Database;

use Illuminate\Database\ConnectionInterface;
use Ramsey\Uuid\Uuid;
use Wob\Library\Domain\Model\Asset;
use Wob\Library\Domain\Repository\AssetRepository;
use Wob\Library\Domain\ValueObject\AssetId;
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

    public function ownedBy(OwnerId $ownerId): array
    {
        return $this->db->table("assets")
            ->where("owner_id", $ownerId->value)
            ->orderBy("created_at")
            ->get()
            ->map($this->hydrate(...))
            ->all();
    }

    public function save(Asset $asset): void
    {
        $values = [
            "owner_id" => $asset->ownerId->value,
            "public_id" => $asset->id->value,
            "type" => $asset->type,
            "title" => $asset->title(),
            "data" => json_encode($asset->data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            "updated_at" => now(),
        ];

        $this->db->table("assets")->upsert(
            [["id" => Uuid::uuid4()->toString(), ...$values, "created_at" => now()]],
            ["owner_id", "public_id"],
            ["type", "title", "data", "updated_at"],
        );
    }

    public function remove(Asset $asset): void
    {
        // Hot lists elsewhere may still name this id, and that is fine: the
        // client filters unknown ids out when building the palette. Chasing the
        // references would turn one delete into a write across every story.
        $this->db->table("assets")
            ->where("owner_id", $asset->ownerId->value)
            ->where("public_id", $asset->id->value)
            ->delete();
    }

    private function hydrate(object $row): Asset
    {
        return new Asset(
            new AssetId($row->public_id),
            new OwnerId($row->owner_id),
            $row->type,
            $row->title,
            json_decode($row->data, false, 512, JSON_THROW_ON_ERROR),
        );
    }
}
