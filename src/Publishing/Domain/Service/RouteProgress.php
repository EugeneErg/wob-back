<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Service;

use stdClass;
use Wob\Publishing\Domain\ValueObject\ContentSnapshot;
use Wob\Publishing\Domain\ValueObject\RouteCompletion;

/**
 * Works out how far a player got through their own route.
 *
 * Derived from what the server already knows — which levels of this release
 * they have finished — rather than reported by the client. The number feeds the
 * canon quorum, so a client that could name it directly could crown its own
 * story with one request and a hundred throwaway accounts.
 *
 * "Their own route" is the chapters they entered — but it only counts as a
 * route at all once they have reached an ending.
 *
 * The second half is not decoration, it closes a hole wide enough to walk
 * through. Reading the route as "chapters entered" alone meant that finishing
 * chapter one of a nine-chapter story was 100% of that player's route, and
 * therefore a completed playthrough for quorum purposes. A hundred and fifty
 * people playing three levels could crown a story nobody had seen the end of.
 *
 * An ending is a chapter with nowhere left to go: no node in it leads on to
 * another chapter. Requiring one keeps the branching case working — someone who
 * took one branch to its end has genuinely finished the story their way — while
 * refusing to call quitting after the first chapter a playthrough.
 */
final class RouteProgress
{
    /** @param list<string> $finishedLevelIds */
    public function of(ContentSnapshot $content, array $finishedLevelIds): RouteCompletion
    {
        $finished = array_flip($finishedLevelIds);
        $onRoute = [];
        $reachedAnEnding = false;

        foreach ($content->chapters as $chapter) {
            $levelIds = array_map(
                static fn (stdClass $node): string => (string) $node->levelId,
                $chapter->nodes ?? [],
            );

            $entered = false;

            foreach ($levelIds as $levelId) {
                if (isset($finished[$levelId])) {
                    $entered = true;

                    break;
                }
            }

            if (!$entered) {
                continue;
            }

            foreach ($levelIds as $levelId) {
                $onRoute[$levelId] = true;
            }

            if ($this->isEnding($chapter) && $this->allFinished($levelIds, $finished)) {
                $reachedAnEnding = true;
            }
        }

        // No ending, no route. Everything they played still counts as progress
        // — it is simply not a playthrough, and quorum is about playthroughs.
        if (!$reachedAnEnding) {
            return new RouteCompletion(0, count($onRoute));
        }

        $done = 0;

        foreach (array_keys($onRoute) as $levelId) {
            if (isset($finished[$levelId])) {
                $done++;
            }
        }

        return new RouteCompletion($done, count($onRoute));
    }

    /** A chapter nothing leads on from: the story ends here, or on a branch of it. */
    private function isEnding(stdClass $chapter): bool
    {
        foreach ($chapter->nodes ?? [] as $node) {
            if (isset($node->next) && $node->next !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string>        $levelIds
     * @param array<string, int>  $finished
     */
    private function allFinished(array $levelIds, array $finished): bool
    {
        foreach ($levelIds as $levelId) {
            if (!isset($finished[$levelId])) {
                return false;
            }
        }

        return $levelIds !== [];
    }
}
