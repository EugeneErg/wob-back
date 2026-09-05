<?php

declare(strict_types=1);

namespace Wob\Library\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wob\Library\Application\Command\CreateStory;
use Wob\Library\Application\Command\DeleteStory;
use Wob\Library\Application\Command\UpdateStory;
use Wob\Library\Application\Handler\CreateStoryHandler;
use Wob\Library\Application\Handler\DeleteStoryHandler;
use Wob\Library\Application\Handler\UpdateStoryHandler;
use Wob\Library\Application\Query\LibraryReadModel;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\Service\IdGenerator;

final readonly class StoryController
{
    public function __construct(
        private LibraryReadModel $library,
        private CreateStoryHandler $create,
        private UpdateStoryHandler $update,
        private DeleteStoryHandler $delete,
        private IdGenerator $ids,
    ) {
    }

    /** Covers and titles only — enough to draw the shelf, nothing more. */
    public function shelf(Request $request): JsonResponse
    {
        return new JsonResponse($this->library->shelfOf($this->owner($request)));
    }

    public function show(Request $request, string $storyId): JsonResponse
    {
        $story = $this->library->story($storyId, $this->owner($request));

        if ($story === null) {
            return new JsonResponse(["error" => ["code" => "not_found", "message" => "No such story"]], 404);
        }

        // The fingerprint doubles as an ETag: the client keeps a story it
        // already has instead of downloading every level again.
        return (new JsonResponse($story))->setEtag($story["hash"] . "-" . $story["version"]);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Идентификаторы чеканит сервер. Клиент их больше не присылает —
            // и не может: браузер не знает, что уже занято, а имя, выданное до
            // того, как о нём узнала база, живёт в двух местах сразу и рано или
            // поздно расходится.
            "title" => ["required", "string", "max:200"],
            "cover" => ["required", "string", "max:2000"],
            "chapter.title" => ["required", "string", "max:200"],
            "chapter.image" => ["required", "string", "max:2000"],
        ]);

        // Клиенту нужны оба имени: он кладёт историю и её первую главу к себе
        // ровно под теми, что выдал сервер.
        $storyId = $this->ids->next("story");
        $chapterId = $this->ids->next("ch");

        $story = ($this->create)(new CreateStory(
            $this->owner($request),
            $storyId,
            $chapterId,
            $data["title"],
            $data["cover"],
            $data["chapter"]["title"],
            $data["chapter"]["image"],
        ));

        return new JsonResponse([...$this->stamp($story), "chapterId" => $chapterId], 201);
    }

    public function update(Request $request, string $storyId): JsonResponse
    {
        $data = $request->validate([
            "title" => ["nullable", "string", "max:200"],
            "cover" => ["nullable", "string", "max:2000"],
            "hot" => ["nullable", "array"],
            "hot.*" => ["string", "max:64"],
            "chapterOrder" => ["nullable", "array"],
            "chapterOrder.*" => ["string", "max:64"],
            "startNodeId" => ["nullable", "string", "max:64"],
            "intro" => ["nullable", "string", "max:2000"],
        ]);

        $story = ($this->update)(new UpdateStory(
            $this->owner($request),
            $storyId,
            $data["title"] ?? null,
            $data["cover"] ?? null,
            $data["hot"] ?? null,
            $data["chapterOrder"] ?? null,
            $data["startNodeId"] ?? null,
            $data["intro"] ?? null,
        ));

        return new JsonResponse($this->stamp($story));
    }

    public function destroy(Request $request, string $storyId): JsonResponse
    {
        ($this->delete)(new DeleteStory($this->owner($request), $storyId));

        return new JsonResponse(null, 204);
    }

    /**
     * Every write answers with the new version, so the client can keep editing
     * without a re-read. Without it, the very next save would be a conflict.
     *
     * @return array<string, mixed>
     */
    private function stamp(Story $story): array
    {
        return ["id" => $story->id->value, "version" => $story->version()];
    }

    private function owner(Request $request): string
    {
        return (string) $request->attributes->get("ownerId");
    }
}
