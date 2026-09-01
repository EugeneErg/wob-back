<?php

declare(strict_types=1);

namespace Wob\Achievements\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Wob\Achievements\Domain\Model\Achievement;
use Wob\Achievements\Domain\Model\Award;
use Wob\Achievements\Domain\Repository\AwardRepository;
use Wob\Achievements\Domain\Service\AchievementCatalog;

final readonly class AwardController
{
    public function __construct(
        private AwardRepository $awards,
        private AchievementCatalog $catalog,
    ) {
    }

    /**
     * What this player has earned, and what there is left to earn.
     *
     * Both halves matter. A list of what you have is a trophy cabinet; a list
     * of what exists is a reason to play. Showing only the first makes the
     * system invisible to anyone who has not triggered it yet.
     */
    public function mine(Request $request): JsonResponse
    {
        $userId = (string) $request->attributes->get('ownerId');
        $earned = $this->awards->forUser($userId);
        $have = [];

        foreach ($earned as $award) {
            $have[$award->code][] = $award;
        }

        $all = [];

        foreach ($this->catalog->all() as $achievement) {
            $mine = $have[$achievement->code] ?? [];

            $all[] = [
                'code' => $achievement->code,
                'title' => $achievement->title,
                'description' => $achievement->description,
                'points' => $achievement->points,
                'earned' => $mine !== [],
                // How many times, for the ones earned per story or per board.
                'times' => count($mine),
                'firstEarnedAt' => $mine === [] ? null : end($mine)->awardedAt->format(DATE_ATOM),
            ];
        }

        return new JsonResponse([
            'points' => $this->awards->totalPoints($userId),
            'earned' => count($earned),
            'achievements' => $all,
            'recent' => array_map(
                fn (Award $a): array => [
                    'code' => $a->code,
                    'title' => $this->catalog->get($a->code)->title,
                    'points' => $a->points,
                    'subjectId' => $a->subjectId,
                    'awardedAt' => $a->awardedAt->format(DATE_ATOM),
                ],
                array_slice($earned, 0, 10),
            ),
        ]);
    }

    /** The one board that spans everything a person does. */
    public function ranking(): JsonResponse
    {
        return new JsonResponse(['ranking' => $this->awards->ranking()]);
    }
}
