<?php

declare(strict_types=1);

namespace Wob\Progress\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wob\Progress\Application\Command\CompleteLevel;
use Wob\Progress\Application\Handler\CompleteLevelHandler;
use Wob\Progress\Domain\Repository\ProgressRepository;

final readonly class ProgressController
{
    public function __construct(
        private ProgressRepository $progress,
        private CompleteLevelHandler $complete,
        private LevelResolver $levels,
    ) {
    }

    /**
     * The flat list of finished levels, in the shape the client progress map
     * already uses. What that makes unlocked is worked out on the client, from
     * the chapter graph it is holding anyway.
     */
    public function index(Request $request): JsonResponse
    {
        $done = $this->progress->completedLevelIds($this->userId($request));

        return new JsonResponse(["completed" => $done]);
    }

    public function complete(Request $request): JsonResponse
    {
        $data = $request->validate([
            "storyId" => ["required", "string", "max:64"],
            "levelId" => ["required", "string", "max:64"],
        ]);

        $internalId = $this->levels->internalId($data["storyId"], $data["levelId"]);

        if ($internalId === null) {
            return new JsonResponse(["error" => ["code" => "not_found", "message" => "No such level"]], 404);
        }

        $completion = ($this->complete)(new CompleteLevel($this->userId($request), $internalId));

        return new JsonResponse([
            "levelId" => $data["levelId"],
            "completions" => $completion->completions(),
            "firstCompletedAt" => $completion->firstCompletedAt->format(DATE_ATOM),
        ]);
    }

    private function userId(Request $request): string
    {
        return (string) $request->attributes->get("ownerId");
    }
}
