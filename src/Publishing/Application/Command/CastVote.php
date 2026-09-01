<?php

declare(strict_types=1);

namespace Wob\Publishing\Application\Command;

/**
 * One rating on one level. Rating a whole story is the client sending one of
 * these per finished level, not a different kind of request — there is no such
 * thing as an opinion about a story that is not an opinion about its levels.
 */
final readonly class CastVote
{
    public function __construct(
        public string $voterId,
        public string $releaseId,
        public string $levelId,
        public int $rating,
    ) {
    }
}
