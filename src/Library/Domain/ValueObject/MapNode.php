<?php

declare(strict_types=1);

namespace Wob\Library\Domain\ValueObject;

use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * A place in the story: one point on one chapter map.
 *
 * x and y are percentages of the chapter image, so the map keeps working
 * whatever the picture size.
 *
 * The point has an id of its own because a level may be shown at several of
 * them. It used to be identified by its level, which is why that was
 * impossible. Two points showing the same level are two different places to be
 * — met at different moments, leading onward differently, each with its own
 * film at the end.
 *
 * "next" lists the points this one leads to, and it is a list rather than a
 * single link for a reason worth writing down: a story has one beginning and
 * many endings, and one link per point can only ever produce one ending. The
 * branching lives here.
 *
 * It also replaced two older mechanisms at once — edges, which linked levels
 * inside a chapter, and an exit that led out to a chapter. Those were the same
 * idea drawn at two scales, and having both meant two answers to "what comes
 * after this". A link may cross into another chapter or stay put; the map does
 * not care, and neither does anything reading it.
 *
 * "outro" plays when the level is finished, not before it starts. Before was
 * the first instinct and the wrong one: a new player would sit through a
 * chapter's film and then a level's before touching anything. After a win it is
 * a pause the player has earned, and it belongs to the point rather than the
 * level, so the same level met twice can end two different ways.
 */
final readonly class MapNode
{
    /** @var list<NodeId> */
    public array $next;

    /** @param list<NodeId> $next */
    public function __construct(
        public NodeId $id,
        public LevelId $levelId,
        public float $x,
        public float $y,
        array $next = [],

        // Что игрок видит в этом месте. Имя и картинка принадлежат точке, а не
        // уровню: один и тот же уровень, встреченный второй раз, — другое
        // место в истории, со своим названием, своей картинкой и своим роликом.
        // На уровне остаётся рабочее имя, которым автор различает их в панели.
        public string $name = '',
        public string $image = '',
        public string $outro = '',
    ) {
        foreach (["x" => $x, "y" => $y] as $name => $value) {
            if ($value < 0 || $value > 100) {
                throw InvariantViolation::because(
                    sprintf("Map node %s must be a percentage between 0 and 100, got %s", $name, $value),
                );
            }
        }

        $seen = [];

        foreach ($next as $child) {
            if ($child->equals($id)) {
                throw InvariantViolation::because(
                    sprintf("Point %s cannot lead to itself", $id->value),
                );
            }

            // Listing the same child twice is not a second path, it is a
            // duplicate that would show one road drawn on top of another.
            $seen[$child->value] = $child;
        }

        $this->next = array_values($seen);
    }

    /** @param list<NodeId> $next */
    public function withNext(array $next): self
    {
        return new self(
            $this->id, $this->levelId, $this->x, $this->y, $next,
            $this->name, $this->image, $this->outro,
        );
    }

    /** Drops links to any of the given points, for when those points are gone. */
    public function withoutLinksTo(NodeId ...$gone): self
    {
        return $this->withNext(array_values(array_filter(
            $this->next,
            static function (NodeId $child) use ($gone): bool {
                foreach ($gone as $g) {
                    if ($child->equals($g)) {
                        return false;
                    }
                }

                return true;
            },
        )));
    }

    /** A point leading nowhere is an ending. One beginning, many of these. */
    public function isEnding(): bool
    {
        return $this->next === [];
    }
}
