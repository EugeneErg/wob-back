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
use Wob\Library\Domain\ValueObject\MapEdge;
use Wob\Library\Domain\ValueObject\MapNode;
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
 *  - a chapter with no story gets a shelter story, because a chapter that
 *    belongs to nothing cannot be reached or played.
 *
 * All three match what the client already does, which matters: a file that
 * round-trips through the server must come back the same as one loaded locally.
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
    private function readAssets(mixed $raw, array $existing): array
    {
        $assets = [];

        foreach ($this->objects($raw) as $item) {
            $old = (string) ($item->id ?? '');
            $type = (string) ($item->type ?? '');
            $title = (string) ($item->title ?? $type);
            $data = $item->data ?? new stdClass();

            if (!$data instanceof stdClass) {
                throw InvariantViolation::because('Asset data must be an object');
            }

            // An identical asset already on the shelf is reused rather than
            // duplicated. Importing the same story twice should not leave two of
            // every anchor in the palette.
            $same = $this->identicalAsset($existing, $type, $title, $data);

            if ($same !== null) {
                $this->ids->pointAt($old, $same->id->value);

                continue;
            }

            $assets[] = new Asset(
                new AssetId($this->ids->reserveAsset($old)),
                $this->owner,
                $type,
                $title,
                $data,
            );
        }

        return $assets;
    }

    /** @param list<Asset> $existing */
    private function identicalAsset(array $existing, string $type, string $title, stdClass $data): ?Asset
    {
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);

        foreach ($existing as $asset) {
            if (
                $asset->type === $type
                && $asset->title() === $title
                && json_encode($asset->data(), JSON_THROW_ON_ERROR) === $encoded
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
                (string) ($item->name ?? 'Level'),
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
        $warnings = [];
        $nodes = [];
        $kept = [];

        foreach ($this->objects($raw->nodes ?? []) as $node) {
            $levelRef = (string) ($node->levelId ?? '');

            if (!isset($levels[$levelRef])) {
                $warnings[] = sprintf(
                    'Chapter "%s" points at level "%s", which the file does not contain — that node was dropped',
                    (string) ($raw->title ?? $raw->id ?? '?'),
                    $levelRef,
                );

                continue;
            }

            $next = isset($node->next) ? (string) $node->next : null;

            if ($next !== null && !isset($chaptersInFile[$next])) {
                $warnings[] = sprintf(
                    'Chapter "%s" led on to a chapter outside the file — that exit was cleared',
                    (string) ($raw->title ?? $raw->id ?? '?'),
                );
                $next = null;
            }

            $levelId = $levels[$levelRef]->id;
            $kept[$levelRef] = $levelId;

            $nodes[] = new MapNode(
                $levelId,
                (float) ($node->x ?? 0),
                (float) ($node->y ?? 0),
                $next === null ? null : new ChapterId($chaptersInFile[$next]),
            );
        }

        $edges = [];

        foreach ($this->objects($raw->edges ?? []) as $edge) {
            $from = (string) ($edge->from ?? '');
            $to = (string) ($edge->to ?? '');

            // A path whose ends did not survive goes with them. The alternative
            // is an aggregate that refuses to be built at all.
            if (!isset($kept[$from], $kept[$to])) {
                continue;
            }

            $edges[] = new MapEdge($kept[$from], $kept[$to]);
        }

        $chapter = new Chapter(
            new ChapterId($chaptersInFile[(string) ($raw->id ?? '')]),
            (string) ($raw->title ?? 'Chapter'),
            (string) ($raw->image ?? ''),
            $nodes,
            $edges,
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
                (string) ($item->title ?? 'Story'),
                (string) ($item->cover ?? ''),
                $own,
                $this->levelsUsedBy($own, $levels),
                $this->assetIds($item->hot ?? []),
            );
        }

        // Chapters the file carried without a story to hold them — exporting a
        // single chapter produces exactly this.
        $orphans = array_values(array_diff_key($chapters, $claimed));

        if ($orphans !== []) {
            $warnings[] = sprintf(
                '%d chapter(s) arrived without a story and were put in "Imported chapters"',
                count($orphans),
            );

            $stories[] = new Story(
                new StoryId($this->ids->reserve('story', 'imported-' . bin2hex(random_bytes(3)), true)),
                $this->owner,
                'Imported chapters',
                'linear-gradient(140deg,#4a3a5c,#16242b)',
                $orphans,
                $this->levelsUsedBy($orphans, $levels),
            );
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
