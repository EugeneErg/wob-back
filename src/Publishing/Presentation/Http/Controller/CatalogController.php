<?php

declare(strict_types=1);

namespace Wob\Publishing\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wob\Publishing\Application\Query\CatalogReadModel;

/**
 * What there is to play, and the content to play it with.
 *
 * Open to signed-out visitors on purpose, and trimmed for them just as
 * deliberately: they get the first canonical story and, inside it, one level.
 * The trimming happens in the read model rather than here, so there is no
 * route to the full content that skips it.
 */
final readonly class CatalogController
{
    public function __construct(private CatalogReadModel $catalog)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $playerId = $this->playerId($request);

        if ($playerId === null) {
            $taste = $this->catalog->forVisitor();

            return new JsonResponse([
                'canon' => $taste === null ? [] : [$taste],
                'published' => [],
                'preview' => true,
            ]);
        }

        return new JsonResponse([
            'canon' => $this->catalog->canon(),
            'published' => $this->catalog->published(),
            'preview' => false,
        ]);
    }

    public function play(Request $request, string $storyId): JsonResponse
    {
        $story = $this->catalog->play($storyId, $this->playerId($request));

        if ($story === null) {
            // The same answer whether the story does not exist or is not on
            // offer to this visitor. Telling them apart would let anyone map
            // the catalogue by guessing ids.
            return new JsonResponse(
                ['error' => ['code' => 'not_found', 'message' => 'No such story']],
                404,
            );
        }

        return new JsonResponse($story);
    }

    /**
     * The signed-in player, or null.
     *
     * Read from the guard rather than from the middleware attribute, because
     * these routes are open: ResolveDomainUser never ran, and its absence is
     * exactly what "signed out" means here.
     */
    private function playerId(Request $request): ?string
    {
        $id = auth()->guard('web')->id();

        return $id === null ? null : (string) $id;
    }
}
