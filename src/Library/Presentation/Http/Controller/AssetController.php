<?php

declare(strict_types=1);

namespace Wob\Library\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wob\Library\Domain\Model\Asset;
use Wob\Library\Domain\Repository\AssetRepository;
use Wob\Library\Domain\Service\IdGenerator;
use Wob\Library\Domain\ValueObject\AssetId;
use Wob\Library\Domain\ValueObject\EntityPlacement;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Shared\Domain\Exception\NotFound;

/**
 * The author's shelf.
 *
 * Assets used to live only inside a library bundle, which meant the shelf was
 * really the client's and the server merely stored a copy on upload. That is
 * backwards for the one thing on the shelf that is not part of any story: it
 * belongs to the author, is shared across everything they make, and should
 * survive a browser being cleared.
 *
 * So it gets routes of its own. Stories still mark some of them hot, and that
 * stays a reference by id across an aggregate boundary — a hot id may name an
 * asset that has since been deleted, and the palette simply skips it.
 */
final readonly class AssetController
{
    public function __construct(
        private AssetRepository $assets,
        private IdGenerator $ids,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return new JsonResponse([
            'assets' => array_map($this->describe(...), $this->assets->ownedBy($this->owner($request))),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, []);

        $asset = new Asset(
            new AssetId($this->ids->next('as')),
            $this->owner($request),
            $data['title'],
            $this->entities($data['entities']),
        );

        $this->assets->save($asset);

        return new JsonResponse($this->describe($asset), 201);
    }

    public function update(Request $request, string $assetId): JsonResponse
    {
        $owner = $this->owner($request);
        $asset = $this->assets->find(new AssetId($assetId), $owner) ?? throw NotFound::of('Asset', $assetId);

        $data = $this->validated($request, []);

        $asset->rename($data['title']);
        $asset->replaceEntities($this->entities($data['entities']));

        $this->assets->save($asset);

        return new JsonResponse($this->describe($asset));
    }

    public function destroy(Request $request, string $assetId): JsonResponse
    {
        $owner = $this->owner($request);
        $asset = $this->assets->find(new AssetId($assetId), $owner) ?? throw NotFound::of('Asset', $assetId);

        $this->assets->remove($asset);

        return new JsonResponse(['id' => $assetId]);
    }

    /**
     * @param array<string, list<string>> $extra
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $extra): array
    {
        return $request->validate([
            ...$extra,
            'title' => ['required', 'string', 'max:200'],

            // The list is checked as an envelope and no further. What a "motor"
            // is remains the game's business — the server has to store a type
            // that shipped after it was deployed and hand it back untouched.
            'entities' => ['required', 'array', 'min:1'],
            'entities.*.id' => ['required', 'string', 'max:64'],
            'entities.*.type' => ['required', 'string', 'max:64'],
            'entities.*.data' => ['present'],
            'entities.*.parent' => ['nullable', 'string', 'max:64'],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $raw
     *
     * @return list<EntityPlacement>
     */
    private function entities(array $raw): array
    {
        return array_map(
            static fn (array $e): EntityPlacement => EntityPlacement::fromObject((object) [
                'id' => $e['id'],
                'type' => $e['type'],
                'data' => (object) ($e['data'] ?? []),
                'parent' => $e['parent'] ?? null,
            ]),
            $raw,
        );
    }

    private function owner(Request $request): OwnerId
    {
        return new OwnerId((string) $request->attributes->get('ownerId'));
    }

    /** @return array<string, mixed> */
    private function describe(Asset $asset): array
    {
        return [
            'id' => $asset->id->value,
            'title' => $asset->title(),

            // The types inside, so a palette can group without unpacking every
            // asset itself. A group of several belongs under all of them.
            'types' => $asset->types(),
            'entities' => $asset->entities(),
        ];
    }
}
