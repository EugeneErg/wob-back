<?php

declare(strict_types=1);

namespace Wob\Library\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wob\Library\Application\Command\CreateChapter;
use Wob\Library\Application\Command\DeleteChapter;
use Wob\Library\Application\Command\SaveChapterMap;
use Wob\Library\Application\Command\EditMap;
use Wob\Library\Application\Handler\CreateChapterHandler;
use Wob\Library\Application\Handler\EditMapHandler;
use Wob\Library\Application\Handler\DeleteChapterHandler;
use Wob\Library\Application\Handler\SaveChapterMapHandler;
use Wob\Library\Domain\Service\IdGenerator;

final readonly class ChapterController
{
    public function __construct(
        private CreateChapterHandler $create,
        private SaveChapterMapHandler $saveMap,
        private DeleteChapterHandler $delete,
        private IdGenerator $ids,
        private EditMapHandler $edit,
    ) {
    }

    /*
     * Мелкие правки карты.
     *
     * Каждая — про один объект и ничего не знает об остальной карте. Версии тут
     * нет и быть не должно: две правки разных точек не пересекаются, а две
     * правки одной сходятся к последней.
     */

    public function describe(Request $request, string $storyId, string $chapterId): JsonResponse
    {
        $data = $request->validate([
            "title" => ["required", "string", "max:200"],
            "image" => ["nullable", "string", "max:2000"],
            "map" => ["nullable", "string", "max:2000"],
        ]);

        ($this->edit)(EditMap::chapter(
            $this->owner($request),
            $storyId,
            $chapterId,
            $data["title"],
            (string) ($data["image"] ?? ""),
            (string) ($data["map"] ?? ""),
        ));

        return new JsonResponse(["ok" => true]);
    }

    public function editNode(Request $request, string $storyId, string $chapterId, string $nodeId): JsonResponse
    {
        $data = $request->validate([
            "x" => ["nullable", "numeric", "between:0,100"],
            "y" => ["nullable", "numeric", "between:0,100"],
            "name" => ["nullable", "string", "max:200"],
            "image" => ["nullable", "string", "max:2000"],
            "outro" => ["nullable", "string", "max:2000"],
        ]);

        ($this->edit)(EditMap::node(
            $this->owner($request),
            $storyId,
            $chapterId,
            $nodeId,
            isset($data["x"]) ? (float) $data["x"] : null,
            isset($data["y"]) ? (float) $data["y"] : null,
            $data["name"] ?? null,
            $data["image"] ?? null,
            $data["outro"] ?? null,
        ));

        return new JsonResponse(["ok" => true]);
    }

    public function link(Request $request, string $storyId): JsonResponse
    {
        $data = $request->validate([
            "from" => ["required", "string", "max:64"],
            "to" => ["required", "string", "max:64"],
        ]);

        ($this->edit)(EditMap::link($this->owner($request), $storyId, $data["from"], $data["to"], true));

        return new JsonResponse(["ok" => true], 201);
    }

    public function unlink(Request $request, string $storyId, string $from, string $to): JsonResponse
    {
        ($this->edit)(EditMap::link($this->owner($request), $storyId, $from, $to, false));

        return new JsonResponse(["ok" => true]);
    }

    public function create(Request $request, string $storyId): JsonResponse
    {
        $data = $request->validate([
            "title" => ["required", "string", "max:200"],
            "image" => ["required", "string", "max:2000"],
        ]);

        // Имя выдаёт сервер и возвращает его клиенту.
        $chapterId = $this->ids->next("ch");

        $story = ($this->create)(new CreateChapter(
            $this->owner($request),
            $storyId,
            $chapterId,
            $data["title"],
            $data["image"],
        ));

        return new JsonResponse(["id" => $chapterId, "version" => $story->version()], 201);
    }

    public function saveMap(Request $request, string $storyId, string $chapterId): JsonResponse
    {
        $data = $request->validate([
            "title" => ["nullable", "string", "max:200"],
            "image" => ["nullable", "string", "max:2000"],
            "map" => ["nullable", "string", "max:2000"],
            "canvas" => ["nullable", "array"],
            "canvas.x" => ["required_with:canvas", "numeric"],
            "canvas.y" => ["required_with:canvas", "numeric"],
            "canvas.w" => ["required_with:canvas", "numeric"],
            "canvas.h" => ["required_with:canvas", "numeric"],
            "nodes" => ["present", "array"],
            "nodes.*.id" => ["nullable", "string", "max:64"],
            "nodes.*.levelId" => ["required", "string", "max:64"],
            "nodes.*.name" => ["nullable", "string", "max:200"],
            "nodes.*.image" => ["nullable", "string", "max:2000"],
            "nodes.*.outro" => ["nullable", "string", "max:2000"],
            "nodes.*.x" => ["required", "numeric", "between:0,100"],
            "nodes.*.y" => ["required", "numeric", "between:0,100"],
            "nodes.*.next" => ["nullable", "array"],
            "nodes.*.next.*" => ["string", "max:64"],
        ]);

        $story = ($this->saveMap)(new SaveChapterMap(
            $this->owner($request),
            $storyId,
            $chapterId,
            $data["title"] ?? null,
            $data["image"] ?? null,
            $data["nodes"],
            $data["map"] ?? null,
            $data["canvas"] ?? null,
        ));

        return new JsonResponse(["id" => $chapterId, "version" => $story->version()]);
    }

    public function destroy(Request $request, string $storyId, string $chapterId): JsonResponse
    {
        $story = ($this->delete)(new DeleteChapter(
            $this->owner($request),
            $storyId,
            $chapterId,
        ));

        return new JsonResponse(["version" => $story->version()]);
    }

    private function owner(Request $request): string
    {
        return (string) $request->attributes->get("ownerId");
    }
}
