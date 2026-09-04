<?php

declare(strict_types=1);

namespace Wob\Media\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Media\Application\Command\UploadMedia;
use Wob\Media\Application\Handler\UploadMediaHandler;
use Wob\Media\Domain\Model\Media;
use Wob\Media\Domain\Port\MediaStore;
use Wob\Media\Domain\Repository\MediaRepository;
use Wob\Media\Domain\ValueObject\MediaId;
use Wob\Shared\Domain\Exception\AccessDenied;
use Wob\Shared\Domain\Exception\InvariantViolation;
use Wob\Shared\Domain\Exception\NotFound;

final readonly class MediaController
{
    public function __construct(
        private UploadMediaHandler $upload,
        private MediaRepository $media,
        private MediaStore $store,
    ) {
    }

    public function upload(Request $request): JsonResponse
    {
        $file = $request->file('file');

        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw InvariantViolation::because('No file arrived');
        }

        $media = ($this->upload)(new UploadMedia($this->owner($request), $file));

        return new JsonResponse($this->describe($media), 201);
    }

    /** Everything this author has uploaded, for picking a cover or an intro. */
    public function index(Request $request): JsonResponse
    {
        return new JsonResponse([
            'media' => array_map($this->describe(...), $this->media->ownedBy($this->owner($request))),
        ]);
    }

    /**
     * The bytes.
     *
     * Behind a session and an ownership check, like every other draft route:
     * an unreleased intro is part of an unreleased story, and a random id is
     * not a permission. When published content starts pointing at media this
     * will need a public path too, but guessing at that shape now would mean
     * guessing wrong.
     */
    public function show(Request $request, string $id): StreamedResponse
    {
        $media = $this->media->find(new MediaId($id));

        if ($media === null || !$this->store->exists($media->path())) {
            throw NotFound::of('Media', $id);
        }

        if (!$media->belongsTo($this->owner($request))) {
            throw AccessDenied::of('Media', $id);
        }

        return $this->stream($media);
    }

    private function stream(Media $media): StreamedResponse
    {
        $headers = [
            'Content-Type' => $media->mime(),
            'Content-Length' => (string) $media->bytes(),

            // The bytes for one id never change — the id is minted per upload
            // and nothing overwrites it — so this is one of the few things in
            // the app that can genuinely be cached forever. It matters most for
            // the case this exists to serve: an intro that would otherwise be
            // re-fetched every time a player restarts a level.
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ];

        $stream = $this->store->readStream($media->path());

        return new StreamedResponse(static function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers);
    }

    private function owner(Request $request): OwnerId
    {
        return new OwnerId((string) $request->attributes->get('ownerId'));
    }

    /** @return array<string, mixed> */
    private function describe(Media $media): array
    {
        return [
            'id' => $media->id()->value,
            'kind' => $media->kind()->value,
            'mime' => $media->mime(),
            'bytes' => $media->bytes(),
            'name' => $media->originalName(),
            'url' => '/api/media/' . $media->id()->value,
            'uploadedAt' => $media->createdAt()->format(DATE_ATOM),
        ];
    }
}
