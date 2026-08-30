<?php

declare(strict_types=1);

namespace Wob\Library\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wob\Library\Application\Command\CreateChapter;
use Wob\Library\Application\Command\DeleteChapter;
use Wob\Library\Application\Command\SaveChapterMap;
use Wob\Library\Application\Handler\CreateChapterHandler;
use Wob\Library\Application\Handler\DeleteChapterHandler;
use Wob\Library\Application\Handler\SaveChapterMapHandler;

final readonly class ChapterController
{
    public function __construct(
        private CreateChapterHandler $create,
        private SaveChapterMapHandler $saveMap,
        private DeleteChapterHandler $delete,
    ) {
    }

    public function create(Request $request, string $storyId): JsonResponse
    {
        $data = $request->validate([
            "id" => ["required", "string", "max:64"],
            "title" => ["required", "string", "max:200"],
            "image" => ["required", "string", "max:2000"],
            "version" => ["required", "integer", "min:0"],
        ]);

        $story = ($this->create)(new CreateChapter(
            $this->owner($request),
            $storyId,
            $data["id"],
            $data["title"],
            $data["image"],
            (int) $data["version"],
        ));

        return new JsonResponse(["id" => $data["id"], "version" => $story->version()], 201);
    }

    public function saveMap(Request $request, string $storyId, string $chapterId): JsonResponse
    {
        $data = $request->validate([
            "title" => ["nullable", "string", "max:200"],
            "image" => ["nullable", "string", "max:2000"],
            "nodes" => ["present", "array"],
            "nodes.*.levelId" => ["required", "string", "max:64"],
            "nodes.*.x" => ["required", "numeric", "between:0,100"],
            "nodes.*.y" => ["required", "numeric", "between:0,100"],
            "nodes.*.next" => ["nullable", "string", "max:64"],
            "edges" => ["present", "array"],
            "edges.*.from" => ["required", "string", "max:64"],
            "edges.*.to" => ["required", "string", "max:64"],
            "version" => ["required", "integer", "min:0"],
        ]);

        $story = ($this->saveMap)(new SaveChapterMap(
            $this->owner($request),
            $storyId,
            $chapterId,
            $data["title"] ?? null,
            $data["image"] ?? null,
            $data["nodes"],
            $data["edges"],
            (int) $data["version"],
        ));

        return new JsonResponse(["id" => $chapterId, "version" => $story->version()]);
    }

    public function destroy(Request $request, string $storyId, string $chapterId): JsonResponse
    {
        $data = $request->validate(["version" => ["required", "integer", "min:0"]]);

        $story = ($this->delete)(new DeleteChapter(
            $this->owner($request),
            $storyId,
            $chapterId,
            (int) $data["version"],
        ));

        return new JsonResponse(["version" => $story->version()]);
    }

    private function owner(Request $request): string
    {
        return (string) $request->attributes->get("ownerId");
    }
}
