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
     * The bytes. Open to anyone who has the id.
     *
     * This used to be behind a session and an ownership check, on the reasoning
     * that a random id is not a permission and an unreleased intro belongs to
     * an unreleased story. Consistent, and wrong about what a file is here.
     *
     * A file is not part of one story. It is uploaded once and referred to by
     * id, and the author's decision is that anybody may use anybody's — that is
     * what the media shelf is for, and using someone else's is meant to earn
     * them something later. A picture that only its uploader can fetch cannot
     * be reused by anyone, so the shelf would show files that go blank the
     * moment a second author picks one.
     *
     * The immediate cause was smaller and unarguable: a released story refers
     * to the files it is made of, and it is meant to be played by strangers.
     * Every picture in an imported story was a 403 for everyone except the
     * person who ran the import.
     *
     * What is left private is the list, not the file. `index` still answers
     * with one author's uploads, because "what have I got" is a different
     * question from "give me this file", and knowing an id is still the only
     * way in — the shelf is what turns ids into something browsable.
     */
    public function show(string $id): StreamedResponse
    {
        $media = $this->media->find(new MediaId($id));

        if ($media === null || !$this->store->exists($media->path())) {
            throw NotFound::of('Media', $id);
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
            //
            // Public rather than private now that the file is. Under 'private'
            // a shared cache must keep a copy per session, and the one picture
            // a hundred players load from the same imported story would be
            // fetched a hundred times from the origin.
            'Cache-Control' => 'public, max-age=31536000, immutable',
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
