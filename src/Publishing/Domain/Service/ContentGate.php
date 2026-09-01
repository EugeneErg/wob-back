<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Service;

use stdClass;
use Wob\Publishing\Domain\ValueObject\ContentSnapshot;

/**
 * How much of a release a given viewer is allowed to receive.
 *
 * The gate is enforced by not sending the content, never by sending it with a
 * flag saying not to show it. That distinction is the whole point: the client
 * is a browser, and anything it is handed it can read. A locked level that
 * arrives in the payload is not locked.
 *
 * Signed out, a visitor gets exactly one level — the first level of the first
 * chapter of the oldest canonical story — and enough of the map around it to
 * see that there is more. Signed in, they get everything the release contains.
 */
final class ContentGate
{
    /**
     * Trim a release down to what a signed-out visitor may have.
     *
     * The chapter is kept, with its map intact, rather than reduced to a single
     * node. Someone who beats the one open level should be able to see the path
     * running on to the next one: the reason to sign in is far more persuasive
     * when it is visible than when it is described.
     */
    public function forVisitor(ContentSnapshot $content): ContentSnapshot
    {
        $chapter = $content->chapters[0] ?? null;

        if ($chapter === null) {
            return new ContentSnapshot([], []);
        }

        $firstNode = ($chapter->nodes ?? [])[0] ?? null;

        if ($firstNode === null) {
            return new ContentSnapshot([$chapter], []);
        }

        $level = $content->level($firstNode->levelId);

        return new ContentSnapshot(
            [$this->firstChapterOnly($chapter)],
            $level === null ? [] : [$level],
        );
    }

    /**
     * The chapter as the visitor sees it: every node still on the map, because
     * the shape of what is ahead is the invitation — but the levels behind them
     * are simply not in the payload, so the map draws them and the game cannot
     * start them.
     */
    private function firstChapterOnly(stdClass $chapter): stdClass
    {
        $trimmed = clone $chapter;
        $trimmed->nodes = $chapter->nodes ?? [];
        $trimmed->edges = $chapter->edges ?? [];

        return $trimmed;
    }
}
