<?php

declare(strict_types=1);

namespace Wob\Progress\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wob\Progress\Application\Command\CompleteLevel;
use Wob\Progress\Application\Handler\CompleteLevelHandler;
use Wob\Progress\Domain\Repository\ProgressRepository;
use Wob\Publishing\Application\Handler\RecordRouteProgressHandler;

final readonly class ProgressController
{
    public function __construct(
        private ProgressRepository $progress,
        private CompleteLevelHandler $complete,
        private LevelResolver $levels,
        private RecordRouteProgressHandler $route,
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

            // Which run this belongs to. Optional, because a player who has
            // not started a slot is still playing, and their progress still
            // counts — it just belongs to them rather than to a particular run.
            "slotId" => ["nullable", "string", "uuid"],
        ]);

        // The level is named by its public id, not resolved to a row.
        //
        // Resolving was how progress ended up hostage to the author's draft: a
        // deleted row took the progress of everyone playing a frozen release
        // with it. Existence is still checked, but against the story rather
        // than as a foreign key.
        if (!$this->levels->existsIn($data["storyId"], $data["levelId"])) {
            return new JsonResponse(["error" => ["code" => "not_found", "message" => "No such level"]], 404);
        }

        $completion = ($this->complete)(new CompleteLevel(
            $this->userId($request),
            $data["levelId"],
            $data["slotId"] ?? null,
        ));

        // Recomputed here because this is the only moment the answer changes.
        // It feeds the canon quorum and the author's own clearance, and until
        // something called it neither could ever happen.
        if ($completion->slotId !== null) {
            ($this->route)($this->userId($request), $completion->slotId);
        }

        return new JsonResponse([
            "levelId" => $data["levelId"],
            "slotId" => $completion->slotId,
            "completions" => $completion->completions(),
            "firstCompletedAt" => $completion->firstCompletedAt->format(DATE_ATOM),
        ]);
    }

    private function userId(Request $request): string
    {
        return (string) $request->attributes->get("ownerId");
    }
}
