<?php

declare(strict_types=1);

namespace Wob\Media\Domain\Port;

use Wob\Media\Domain\ValueObject\MediaId;

/**
 * Where the bytes go.
 *
 * A port rather than a direct call to Laravel's Storage facade, for one
 * concrete reason: the first deployment writes to a local disk, and the first
 * time two servers run at once that stops working, because a video uploaded to
 * one of them is a 404 on the other. When that day comes the fix should be a
 * different implementation of this interface, not a search through the codebase
 * for everywhere a path was assumed to be local.
 *
 * Nothing here returns a URL. How bytes reach a browser — streamed by the app,
 * or a signed link straight to a bucket — is the implementation's business, and
 * the domain has no opinion about it.
 */
interface MediaStore
{
    /** @param resource|string $contents */
    public function put(MediaId $id, string $extension, mixed $contents): string;

    /** @return resource */
    public function readStream(string $path): mixed;

    public function delete(string $path): void;

    public function exists(string $path): bool;
}
