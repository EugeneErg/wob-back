<?php

declare(strict_types=1);

namespace Wob\Media\Domain\Model;

use DateTimeImmutable;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Media\Domain\ValueObject\MediaId;
use Wob\Media\Domain\ValueObject\MediaKind;

/**
 * An uploaded file, minus the bytes.
 *
 * The bytes live behind MediaStore; this is only the record that says they
 * exist, who they belong to and what they may be used as. Keeping them apart
 * is what lets the disk be swapped for object storage without the database
 * knowing, and it keeps a sixty-megabyte video from ever being loaded into
 * memory just to answer "does this id belong to you".
 */
final readonly class Media
{
    public function __construct(
        private MediaId $id,
        private OwnerId $owner,
        private MediaKind $kind,
        private string $mime,
        private int $bytes,
        private string $path,
        private string $originalName,
        private DateTimeImmutable $createdAt,
    ) {
    }

    public function id(): MediaId
    {
        return $this->id;
    }

    public function owner(): OwnerId
    {
        return $this->owner;
    }

    public function kind(): MediaKind
    {
        return $this->kind;
    }

    public function mime(): string
    {
        return $this->mime;
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function originalName(): string
    {
        return $this->originalName;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function belongsTo(OwnerId $owner): bool
    {
        return $this->owner->equals($owner);
    }
}
