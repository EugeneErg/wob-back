<?php

declare(strict_types=1);

namespace Wob\Library\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use JsonException;
use stdClass;
use Wob\Library\Application\Command\ImportBundle;
use Wob\Library\Application\Handler\ImportBundleHandler;
use Wob\Library\Application\Query\LibraryReadModel;
use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * Files in and out — the same format the game already reads and writes.
 *
 * This is also the migration path, and the reason it comes before releases or
 * leaderboards: everyone who has played so far has a library in localStorage,
 * and an account they cannot move it into is an account they will not use.
 */
final readonly class BundleController
{
    public function __construct(
        private LibraryReadModel $library,
        private ImportBundleHandler $import,
    ) {
    }

    public function exportLibrary(Request $request): JsonResponse
    {
        return $this->asFile(
            $this->library->libraryBundle($this->owner($request)),
            'wob-library',
        );
    }

    public function exportStory(Request $request, string $storyId): JsonResponse
    {
        $bundle = $this->library->storyBundle($storyId, $this->owner($request));

        if ($bundle === null) {
            return new JsonResponse(['error' => ['code' => 'not_found', 'message' => 'No such story']], 404);
        }

        return $this->asFile($bundle, 'wob-' . $storyId);
    }

    public function import(Request $request): JsonResponse
    {
        // Decoded from the raw body, not from $request->all(): Laravel would
        // hand back associative arrays, and an entity whose data is an empty
        // object would arrive as an empty array. That changes the level's
        // content hash, which is what every recording of it is keyed on — an
        // import that silently renumbers content is worse than one that fails.
        $body = $this->decode($request->getContent());

        $bundle = $body->bundle ?? $body;

        if (!$bundle instanceof stdClass) {
            throw InvariantViolation::because('Expected a story file');
        }

        $result = ($this->import)(new ImportBundle($this->owner($request), $bundle));

        return new JsonResponse([
            'stories' => $result->stories,
            // What each id in the file became. The client is still holding that
            // file and its ids may no longer be the ids the content lives under.
            'idMap' => $result->idMap,
            'warnings' => $result->warnings,
        ], 201);
    }

    private function decode(string $raw): stdClass
    {
        try {
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvariantViolation::because('That file is not valid JSON');
        }

        if (!$decoded instanceof stdClass) {
            throw InvariantViolation::because('Expected a story file');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $bundle */
    private function asFile(array $bundle, string $name): JsonResponse
    {
        return (new JsonResponse($bundle, 200, [
            'Content-Disposition' => sprintf('attachment; filename="%s.json"', $name),
        ]))->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function owner(Request $request): string
    {
        return (string) $request->attributes->get('ownerId');
    }
}
