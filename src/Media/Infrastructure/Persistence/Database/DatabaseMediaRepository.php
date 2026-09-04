<?php

declare(strict_types=1);

namespace Wob\Media\Infrastructure\Persistence\Database;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use stdClass;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Media\Domain\Model\Media;
use Wob\Media\Domain\Repository\MediaRepository;
use Wob\Media\Domain\ValueObject\MediaId;
use Wob\Media\Domain\ValueObject\MediaKind;

final readonly class DatabaseMediaRepository implements MediaRepository
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function save(Media $media): void
    {
        $this->db->table('media')->insert([
            'id' => $media->id()->value,
            'owner_id' => $media->owner()->value,
            'kind' => $media->kind()->value,
            'mime' => $media->mime(),
            'bytes' => $media->bytes(),
            'path' => $media->path(),
            'original_name' => $media->originalName(),
            'created_at' => $media->createdAt()->format('Y-m-d H:i:s'),
        ]);
    }

    public function find(MediaId $id): ?Media
    {
        $row = $this->db->table('media')->where('id', $id->value)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function ownedBy(OwnerId $owner): array
    {
        $rows = $this->db->table('media')
            ->where('owner_id', $owner->value)
            ->orderByDesc('created_at')
            ->get();

        return array_map($this->hydrate(...), iterator_to_array($rows));
    }

    private function hydrate(stdClass $row): Media
    {
        return new Media(
            new MediaId((string) $row->id),
            new OwnerId((string) $row->owner_id),
            MediaKind::from((string) $row->kind),
            (string) $row->mime,
            (int) $row->bytes,
            (string) $row->path,
            (string) $row->original_name,
            new DateTimeImmutable((string) $row->created_at),
        );
    }
}
