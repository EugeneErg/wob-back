<?php

declare(strict_types=1);

namespace Wob\Media\Infrastructure\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Wob\Media\Domain\Port\MediaStore;
use Wob\Media\Domain\ValueObject\MediaId;

/**
 * Bytes on a Laravel filesystem disk.
 *
 * Which disk is configuration, so this same class serves a local folder in
 * development and a bucket in production without editing.
 */
final readonly class DiskMediaStore implements MediaStore
{
    public function __construct(private Filesystem $disk)
    {
    }

    public function put(MediaId $id, string $extension, mixed $contents): string
    {
        // Two levels of fan-out from the id. Directories with a hundred
        // thousand entries are slow to list and unpleasant to look at on a
        // local disk, and the id is already random, so its first four
        // characters spread files evenly without any counter to maintain.
        //
        // The path is built entirely from the id and a whitelisted extension:
        // nothing the uploader chose reaches the filesystem, so there is no
        // name to sanitise and no traversal to defend against.
        $path = sprintf(
            'media/%s/%s/%s%s',
            substr($id->value, 0, 2),
            substr($id->value, 2, 2),
            $id->value,
            $extension === '' ? '' : '.' . $extension,
        );

        $this->disk->put($path, $contents);

        return $path;
    }

    public function readStream(string $path): mixed
    {
        return $this->disk->readStream($path);
    }

    public function delete(string $path): void
    {
        $this->disk->delete($path);
    }

    public function exists(string $path): bool
    {
        return $this->disk->exists($path);
    }
}
