<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\ValueObject;

use stdClass;
use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * The exact content of a release: chapters and levels, frozen.
 *
 * A release does not point at a Story and read it live — that would make "the
 * content of release #3" a moving target the instant the author saves their
 * draft again, and every vote, every record, every leaderboard entry attached
 * to #3 would silently start meaning something else. So a release carries its
 * own copy, in the same shape the client already speaks (ReleaseSnapshot in
 * core/releases.js): a flat list of chapters and levels, each stamped with the
 * content hash it had at the moment of release.
 *
 * Kept as stdClass/arrays rather than rehydrated into Library's Chapter and
 * Level models, on purpose. Library's aggregate exists to enforce invariants
 * while content is being edited; a release is already frozen and has nothing
 * left to enforce. Reusing the editable model here would make Publishing
 * depend on Library's write-side, when all it actually needs is Library's
 * wire shape — the same boundary that already separates the read model from
 * the aggregate.
 */
final readonly class ContentSnapshot
{
    /**
     * @param list<stdClass> $chapters each with id, title, image, nodes, edges, hash
     * @param list<stdClass> $levels   each with id, name, width, height, gravity, goal, entities, hash
     */
    public function __construct(
        public array $chapters,
        public array $levels,
        /**
         * Откуда игрок начинает.
         *
         * Замораживается вместе с содержимым, а не берётся у живой истории:
         * автор может передвинуть начало сразу после выпуска, и тогда игроки
         * старого релиза оказались бы в точке, которой в нём может уже не быть.
         *
         * Пусто у релизов, нарезанных до того, как это поле появилось: они
         * начинаются там же, где начиналась история, и переписывать их задним
         * числом не за чем.
         */
        public ?string $startNodeId = null,
    ) {
        $seen = [];

        foreach ($levels as $level) {
            $id = (string) ($level->id ?? '');

            if ($id === '' || isset($seen[$id])) {
                throw InvariantViolation::because('A release snapshot must have unique, non-empty level ids');
            }

            $seen[$id] = true;
        }
    }

    /**
     * Уровни, стоящие на картах глав.
     *
     * Не то же самое, что $levels, и разница решает исход. В снимок попадает
     * всё, что есть в истории, включая уровни, которые автор сделал и никуда не
     * поставил, — их нельзя открыть, потому что до них нет ни одной точки на
     * карте. Требовать их прохождения значило бы запереть релиз навсегда из-за
     * одного забытого черновика, причём автору даже нечего было бы нажать,
     * чтобы это исправить.
     *
     * @return list<string>
     */
    public function playableLevelIds(): array
    {
        $ids = [];

        foreach ($this->chapters as $chapter) {
            foreach ($chapter->nodes ?? [] as $node) {
                $id = (string) ($node->levelId ?? '');

                if ($id !== '') {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }

    public function level(string $id): ?stdClass
    {
        foreach ($this->levels as $level) {
            if ($level->id === $id) {
                return $level;
            }
        }

        return null;
    }

    public function chapter(string $id): ?stdClass
    {
        foreach ($this->chapters as $chapter) {
            if ($chapter->id === $id) {
                return $chapter;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function levelIds(): array
    {
        return array_map(static fn (stdClass $l): string => $l->id, $this->levels);
    }
}
