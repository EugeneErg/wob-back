<?php

declare(strict_types=1);

namespace Wob\Media\Application\Handler;

use DateTimeImmutable;
use Wob\Media\Application\Command\UploadMedia;
use Wob\Media\Domain\Model\Media;
use Wob\Media\Domain\Port\MediaStore;
use Wob\Media\Domain\Repository\MediaRepository;
use Wob\Media\Domain\ValueObject\MediaId;
use Wob\Media\Domain\ValueObject\MediaKind;
use Wob\Shared\Domain\Exception\InvariantViolation;

final readonly class UploadMediaHandler
{
    public function __construct(
        private MediaRepository $media,
        private MediaStore $store,
    ) {
    }

    public function __invoke(UploadMedia $command): Media
    {
        $file = $command->file;

        // The mime the browser announced is a claim, not a fact, so the real
        // one is read from the bytes. A file that says image/png and is not
        // would otherwise be stored and later served with a content type that
        // does not match it, which is the shape of a good few nasty tricks.
        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());
        $kind = MediaKind::forMime($mime);

        $bytes = (int) $file->getSize();

        if ($bytes <= 0) {
            throw InvariantViolation::because('That file is empty');
        }

        if ($bytes > $kind->maxBytes()) {
            throw InvariantViolation::because(sprintf(
                'That %s is %s; the limit is %s',
                $kind->value,
                self::human($bytes),
                self::human($kind->maxBytes()),
            ));
        }

        $id = MediaId::generate();

        // Written before the row exists. The other order can leave a row
        // pointing at bytes that never arrived, and a broken link in a story is
        // harder to notice than a file nobody references.
        $path = $this->store->put($id, self::extensionFor($mime), $file->getContent());

        $media = new Media(
            $id,
            $command->owner,
            $kind,
            $mime,
            $bytes,
            $path,
            (string) $file->getClientOriginalName(),
            new DateTimeImmutable(),
        );

        $this->media->save($media);

        return $media;
    }

    private static function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            default => '',
        };
    }

    private static function human(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? sprintf('%.1f MB', $bytes / 1024 / 1024)
            : sprintf('%d KB', (int) ceil($bytes / 1024));
    }
}
