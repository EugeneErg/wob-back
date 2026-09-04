<?php

declare(strict_types=1);

namespace Wob\Library\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wob\Library\Application\Command\CreateLevel;
use Wob\Library\Application\Command\DeleteLevel;
use Wob\Library\Application\Command\SaveLevel;
use Wob\Library\Application\Handler\CreateLevelHandler;
use Wob\Library\Application\Handler\DeleteLevelHandler;
use Wob\Library\Application\Handler\SaveLevelHandler;
use Wob\Library\Application\Query\LibraryReadModel;
use Wob\Library\Application\Command\PinLevel;
use Wob\Library\Application\Handler\PinLevelHandler;
use Wob\Library\Domain\Service\IdGenerator;
use Wob\Shared\Domain\Exception\InvariantViolation;
use stdClass;

final readonly class LevelController
{
    public function __construct(
        private LibraryReadModel $library,
        private CreateLevelHandler $create,
        private SaveLevelHandler $save,
        private DeleteLevelHandler $delete,
        private IdGenerator $ids,
        private PinLevelHandler $pin,
    ) {
    }

    public function create(Request $request, string $storyId): JsonResponse
    {
        $data = $request->validate([
            "chapterId" => ["nullable", "string", "max:64"],
            "name" => ["required", "string", "max:200"],
            "x" => ["nullable", "numeric", "between:0,100"],
            "y" => ["nullable", "numeric", "between:0,100"],
            "version" => ["required", "integer", "min:0"],
        ]);

        // И уровень, и точка на карте получают имена здесь: клиент их не
        // придумывает и не может — он не знает, что уже занято.
        $levelId = $this->ids->next("lvl");
        $nodeId = $this->ids->next("nd");

        $story = ($this->create)(new CreateLevel(
            $this->owner($request),
            $storyId,
            $data["chapterId"] ?? null,
            $levelId,
            $data["name"],
            (float) ($data["x"] ?? 50),
            (float) ($data["y"] ?? 50),
            (int) $data["version"],
            $nodeId,
        ));

        return new JsonResponse(["id" => $levelId, "nodeId" => $nodeId, "version" => $story->version()], 201);
    }

    public function save(Request $request, string $storyId, string $levelId): JsonResponse
    {
        $data = $request->validate([
            "name" => ["required", "string", "max:200"],
            "width" => ["required", "integer"],
            "height" => ["required", "integer"],
            "gravity.x" => ["required", "numeric"],
            "gravity.y" => ["required", "numeric"],
            "goal" => ["required", "integer", "min:0", "max:9999"],
            "entities" => ["present", "array"],
            "hot" => ["present", "array"],
            "hot.*" => ["string", "max:64"],
            "image" => ["nullable", "string", "max:2000"],
            "version" => ["required", "integer", "min:0"],
        ]);

        // Entities are re-read from the raw body rather than taken from the
        // validated array. Laravel validation hands back associative arrays, and
        // an entity whose data is an empty object would come out as an empty
        // array — a difference PHP cannot see afterwards but the content hash
        // very much can.
        $entities = $this->rawEntities($request);

        $story = ($this->save)(new SaveLevel(
            $this->owner($request),
            $storyId,
            $levelId,
            $data["name"],
            (int) $data["width"],
            (int) $data["height"],
            (float) $data["gravity"]["x"],
            (float) $data["gravity"]["y"],
            (int) $data["goal"],
            $entities,
            $data["hot"],
            (int) $data["version"],
            $data["image"] ?? null,
        ));

        return new JsonResponse(["id" => $levelId, "version" => $story->version()]);
    }

    public function destroy(Request $request, string $storyId, string $chapterId, string $levelId): JsonResponse
    {
        $data = $request->validate(["version" => ["required", "integer", "min:0"]]);

        $story = ($this->delete)(new DeleteLevel(
            $this->owner($request),
            $storyId,
            $chapterId,
            $levelId,
            (int) $data["version"],
        ));

        return new JsonResponse(["version" => $story->version()]);
    }

    /**
     * A level by content fingerprint — what core/content.js resolves a recording
     * against.
     *
     * Cacheable forever, because a hash names one exact set of bytes and the
     * answer can never change. Private, because thirty-two bits is not a secret
     * and everything here is somebody's unpublished draft. Released content will
     * be the public version of this endpoint.
     */
    public function byHash(Request $request, string $hash): JsonResponse
    {
        $level = $this->library->levelByHash($hash, $this->owner($request));

        if ($level === null) {
            return new JsonResponse(["error" => ["code" => "not_found", "message" => "No content with that hash"]], 404);
        }

        return (new JsonResponse($level))->setMaxAge(31536000)->setPrivate();
    }

    /**
     * The entity list exactly as it arrived, with objects still objects.
     *
     * A non-object in the list is rejected here rather than shrugged off: an
     * entity that is a bare string has no envelope to check and would be stored
     * as content the game cannot load.
     *
     * @return list<stdClass>
     */
    private function rawEntities(Request $request): array
    {
        $body = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $entities = $body->entities ?? [];

        if (!is_array($entities)) {
            throw InvariantViolation::because("Entities must be a list");
        }

        return array_map(
            static fn (mixed $e): stdClass => $e instanceof stdClass
                ? $e
                : throw InvariantViolation::because("Every entity must be an object"),
            array_values($entities),
        );
    }

    private function owner(Request $request): string
    {
        return (string) $request->attributes->get("ownerId");
    }
}
