<?php

declare(strict_types=1);

namespace Wob\Library\Domain\ValueObject;

/**
 * A point on a chapter map.
 *
 * Points used to be identified by the level they showed, which worked only
 * while a level could appear once. It can now appear in several places — the
 * same level met again later in the story, with its own way onward and its own
 * film at the end — so the point needs a name of its own. The level it shows
 * became just another field on it.
 *
 * This is what makes the story a tree rather than a graph with merges: two
 * points showing one level are two distinct places to be, not one place
 * arrived at twice.
 */
final readonly class NodeId extends ClientId
{
    public const PREFIX = "nd";

    protected static function label(): string
    {
        return "Map node id";
    }
}
