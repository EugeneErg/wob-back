<?php

declare(strict_types=1);

namespace Wob\Library\Application\Import;

use stdClass;
use Wob\Library\Domain\Model\Asset;
use Wob\Library\Domain\Model\Chapter;
use Wob\Library\Domain\Model\Level;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\ValueObject\AssetId;
use Wob\Library\Domain\ValueObject\ChapterId;
use Wob\Library\Domain\ValueObject\Dimensions;
use Wob\Library\Domain\ValueObject\EntityPlacement;
use Wob\Library\Domain\ValueObject\Gravity;
use Wob\Library\Domain\ValueObject\LevelId;
use Wob\Library\Domain\ValueObject\MapNode;
use Wob\Library\Domain\ValueObject\NodeId;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * Turns an exported bundle into stories and assets, with every id resolved.
 *
 * The awkward part of import is not parsing, it is that a file written
 * elsewhere makes no promises. It may reference a level it does not contain, or
 * lead out to a chapter that stayed behind, or carry a chapter with no story at
 * all. The aggregate refuses to hold any of that — correctly — so the decision
 * about what to do has to be made here, once, out loud:
 *
 *  - a map node pointing at a level the file does not carry is dropped, along
 *    with the paths touching it. Keeping it would mean refusing the whole
 *    import over one missing level, which turns a partial file into no file;
 *  - an exit leading to a chapter outside the file is cleared. Keeping it would
 *    aim it at whatever chapter happens to own that id here, which is worse
 *    than no exit: the map would show a road onward into a stranger's story;
 *  - a chapter with no story is refused outright, along with anything missing a
 *    title or a name. Those are not damage this side can repair: the author is
 *    the only one who knows what the thing was called and which story it
 *    belonged to, so inventing an answer would bury the mistake instead of
 *    reporting it.
 *
 * The first two repairs drop something the file cannot support; the refusals
 * cover everything the file simply failed to say. Nothing here makes a name up.
 */
final readonly class BundleReader
{
    private const FORMAT = 'goo-bundle';

    public function __construct(private OwnerId $owner, private IdMap $ids)
    {
    }

    /**
     * @param list<Asset> $existingAssets assets this author already has
     *
     * @return array{stories: list<Story>, assets: list<Asset>, warnings: list<string>}
     */
    public function read(stdClass $bundle, array $existingAssets): array
    {
        if (($bundle->format ?? null) !== self::FORMAT) {
            throw InvariantViolation::because('This is not a WOB story file');
        }

        $warnings = [];
        $assets = $this->readAssets($bundle->assets ?? [], $existingAssets);
        $levels = $this->readLevels($bundle->levels ?? []);

        // Every chapter id is reserved before any chapter is read, because an
        // exit may point forward to a chapter further down the file.
        $rawChapters = $this->objects($bundle->chapters ?? []);
        $inFile = [];

        foreach ($rawChapters as $raw) {
            $old = (string) ($raw->id ?? '');
            $inFile[$old] = $this->ids->reserveChapter($old);
        }

        $chapters = [];

        foreach ($rawChapters as $raw) {
            [$chapter, $chapterWarnings] = $this->readChapter($raw, $levels, $inFile);
            $chapters[(string) ($raw->id ?? '')] = $chapter;
            $warnings = [...$warnings, ...$chapterWarnings];
        }

        $stories = $this->readStories($bundle->stories ?? [], $chapters, $levels, $warnings);

        return ['stories' => $stories, 'assets' => $assets, 'warnings' => $warnings];
    }

    /**
     * @param list<Asset> $existing
     *
     * @return list<Asset>
     */
    /**
     * A name the client had to send, or a refusal.
     *
     * Import used to fill a missing title in with something plausible — the
     * asset's type, the word "Chapter", the word "Story". That was a quiet lie:
     * the client is the only side that knows what the author called the thing,
     * so a server-invented name is not a repair, it is a second source of truth
     * that nobody asked for and nobody can correct. Refusing is louder and
     * shorter — the caller finds out at once that it dropped a field, instead of
     * discovering a library full of items called "Story" much later.
     */
    private function required(stdClass $item, string $field, string $what): string
    {
        $value = isset($item->$field) ? trim((string) $item->$field) : '';

        if ($value === '') {
            $id = (string) ($item->id ?? '');

            throw InvariantViolation::because($id === ''
                ? sprintf('Every %s must have a %s', $what, $field)
                : sprintf('%s "%s" has no %s', ucfirst($what), $id, $field));
        }

        return $value;
    }

    private function readAssets(mixed $raw, array $existing): array
    {
        $assets = [];

        foreach ($this->objects($raw) as $item) {
            $old = (string) ($item->id ?? '');
            $title = $this->required($item, 'title', 'asset');
            $entities = $this->assetEntities($item, $old);

            // An identical asset already on the shelf is reused rather than
            // duplicated. Importing the same story twice should not leave two of
            // every anchor in the palette.
            $same = $this->identicalAsset($existing, $title, $entities);

            if ($same !== null) {
                $this->ids->pointAt($old, $same->id->value);

                continue;
            }

            $assets[] = new Asset(
                new AssetId($this->ids->reserveAsset($old)),
                $this->owner,
                $title,
                $entities,
            );
        }

        return $assets;
    }

    /**
     * The entities an asset holds.
     *
     * A file written before assets could hold more than one carries a type and
     * a data blob instead of a list. That is a group of one, so it is read as
     * one rather than refused — the entity takes the asset's own id, which is
     * the only name it has ever had.
     *
     * @return list<EntityPlacement>
     */
    private function assetEntities(stdClass $item, string $old): array
    {
        if (isset($item->entities) && is_array($item->entities)) {
            return array_map(EntityPlacement::fromObject(...), $this->objects($item->entities));
        }

        $data = $item->data ?? new stdClass();

        if (!$data instanceof stdClass) {
            throw InvariantViolation::because('Asset data must be an object');
        }

        return [new EntityPlacement($old, (string) ($item->type ?? ''), $data)];
    }

    /**
     * @param list<Asset>           $existing
     * @param list<EntityPlacement> $entities
     */
    private function identicalAsset(array $existing, string $title, array $entities): ?Asset
    {
        $encoded = json_encode($entities, JSON_THROW_ON_ERROR);

        foreach ($existing as $asset) {
            if (
                $asset->title() === $title
                && json_encode($asset->entities(), JSON_THROW_ON_ERROR) === $encoded
            ) {
                return $asset;
            }
        }

        return null;
    }

    /** @return array<string, Level> keyed by the id the level had in the file */
    private function readLevels(mixed $raw): array
    {
        $levels = [];

        foreach ($this->objects($raw) as $item) {
            $old = (string) ($item->id ?? '');
            $gravity = $item->gravity ?? new stdClass();

            $levels[$old] = new Level(
                new LevelId($this->ids->reserveLevel($old)),
                $this->required($item, 'name', 'level'),
                new Dimensions((int) ($item->width ?? 1600), (int) ($item->height ?? 900)),
                Gravity::fromArray((array) $gravity),
                (int) ($item->goal ?? 0),
                array_map(EntityPlacement::fromObject(...), $this->objects($item->entities ?? [])),
                $this->assetIds($item->hot ?? []),
            );
        }

        return $levels;
    }

    /**
     * @param array<string, Level>  $levels
     * @param array<string, string> $chaptersInFile
     *
     * @return array{Chapter, list<string>}
     */
    private function readChapter(stdClass $raw, array $levels, array $chaptersInFile): array
    {
        // Read before anything else so the warnings below can name the chapter
        // the way its author did, and so a nameless chapter is refused before
        // it has had a chance to produce warnings about its contents.
        $title = $this->required($raw, 'title', 'chapter');

        $warnings = [];
        $nodes = [];
        $kept = [];

        foreach ($this->objects($raw->nodes ?? []) as $node) {
            $levelRef = (string) ($node->levelId ?? '');

            if (!isset($levels[$levelRef])) {
                $warnings[] = sprintf(
                    'Chapter "%s" points at level "%s", which the file does not contain — that node was dropped',
                    $title,
                    $levelRef,
                );

                continue;
            }

            $nextChapter = is_string($node->next ?? null) ? (string) $node->next : null;



            $levelId = $levels[$levelRef]->id;
            $kept[$levelRef] = $levelId;

            $nodes[] = new MapNode(
                // Files written before points had ids carry none, so one is
                // derived from the level — the same rule hydration uses, so a
                // library that round-trips keeps its points.
                new NodeId(isset($node->id) ? (string) $node->id : 'nd-' . $levelId->value),
                $levelId,
                (float) ($node->x ?? 0),
                (float) ($node->y ?? 0),
                // Only a real list counts. An older file carries a chapter name
                // in this field, and casting a string to an array would turn
                // that name into a link to a point that does not exist.
                array_map(
                    static fn (string $c): NodeId => new NodeId($c),
                    array_values(array_filter(
                        is_array($node->next ?? null) ? $node->next : [],
                        static fn (mixed $c): bool => is_string($c),
                    )),
                ),
                (string) ($node->name ?? ''),
                (string) ($node->image ?? ''),
                (string) ($node->outro ?? ''),
            );

            if ($nextChapter !== null) {
                // An exit that named a whole chapter. Points link to points
                // now, and the point it should land on is a decision only the
                // author can make, so it is reported rather than guessed.
                $warnings[] = sprintf(
                    'Chapter "%s" had an exit leading to another chapter; links now join points, so it was dropped and needs redrawing',
                    $title,
                );
            }
        }

        // A library uploaded by an editor that still draws paths between levels
        // rather than between points. The two say the same thing — finish this,
        // open that — so the old form is converted instead of refused.
        $idOfLevel = [];

        foreach ($nodes as $node) {
            $idOfLevel[$node->levelId->value] ??= $node->id;
        }

        $children = [];

        foreach ($this->objects($raw->edges ?? []) as $edge) {
            $from = (string) ($edge->from ?? '');
            $to = (string) ($edge->to ?? '');

            // A path whose ends did not survive goes with them.
            if (!isset($kept[$from], $kept[$to])) {
                continue;
            }

            $fromNode = $idOfLevel[$kept[$from]->value] ?? null;
            $toNode = $idOfLevel[$kept[$to]->value] ?? null;

            if ($fromNode !== null && $toNode !== null) {
                $children[$fromNode->value][] = $toNode;
            }
        }

        if ($children !== []) {
            $nodes = array_map(
                static fn (MapNode $n): MapNode => isset($children[$n->id->value])
                    ? $n->withNext([...$n->next, ...$children[$n->id->value]])
                    : $n,
                $nodes,
            );
        }

        $chapter = new Chapter(
            new ChapterId($chaptersInFile[(string) ($raw->id ?? '')]),
            $title,
            (string) ($raw->image ?? ''),
            $nodes,
            $this->assetIds($raw->hot ?? []),
        );

        return [$chapter, $warnings];
    }

    /**
     * @param array<string, Chapter> $chapters
     * @param array<string, Level>   $levels
     * @param list<string>           $warnings
     *
     * @return list<Story>
     */
    private function readStories(mixed $raw, array $chapters, array $levels, array &$warnings): array
    {
        $stories = [];
        $claimed = [];

        foreach ($this->objects($raw) as $item) {
            $old = (string) ($item->id ?? '');
            $own = [];

            foreach ($this->strings($item->chapters ?? []) as $ref) {
                if (isset($chapters[$ref])) {
                    $own[] = $chapters[$ref];
                    $claimed[$ref] = true;
                }
            }

            $stories[] = new Story(
                new StoryId($this->ids->reserveStory($old)),
                $this->owner,
                $this->required($item, 'title', 'story'),
                (string) ($item->cover ?? ''),
                $own,
                $this->levelsUsedBy($own, $levels),
                $this->assetIds($item->hot ?? []),
            );
        }

        // A chapter nobody claimed. This used to get a shelter story named
        // "Imported chapters", which was the worst of the invented names: the
        // other four at least described one thing the client had sent, while
        // this one conjured a whole story that the author never made and cannot
        // be asked about. Every chapter belongs to a story or the bundle is
        // wrong, and the caller is the only one who can say which story.
        $orphans = array_values(array_diff_key($chapters, $claimed));

        if ($orphans !== []) {
            throw InvariantViolation::because(sprintf(
                '%d chapter(s) arrived without a story — every chapter must belong to one',
                count($orphans),
            ));
        }

        return $stories;
    }

    /**
     * @param list<Chapter>        $chapters
     * @param array<string, Level> $levels
     *
     * @return list<Level>
     */
    private function levelsUsedBy(array $chapters, array $levels): array
    {
        $byNewId = [];

        foreach ($levels as $level) {
            $byNewId[$level->id->value] = $level;
        }

        $used = [];

        foreach ($chapters as $chapter) {
            foreach ($chapter->levelIds() as $levelId) {
                $used[$levelId->value] = $byNewId[$levelId->value];
            }
        }

        return array_values($used);
    }

    /** @return list<AssetId> */
    private function assetIds(mixed $raw): array
    {
        $ids = [];

        foreach ($this->strings($raw) as $id) {
            // A hot id the file did not bring an asset for is dropped rather
            // than kept pointing at a stranger's asset with the same id.
            if ($this->ids->has($id)) {
                $ids[] = new AssetId($this->ids->resolve($id));
            }
        }

        return $ids;
    }

    /** @return list<stdClass> */
    private function objects(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter($raw, static fn (mixed $i): bool => $i instanceof stdClass));
    }

    /** @return list<string> */
    private function strings(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_map(strval(...), array_filter($raw, is_scalar(...))));
    }
}
