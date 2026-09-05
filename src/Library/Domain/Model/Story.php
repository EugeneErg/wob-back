<?php

declare(strict_types=1);

namespace Wob\Library\Domain\Model;

use Wob\Library\Domain\Event\StoryDeleted;
use Wob\Library\Domain\Event\LevelsDiscarded;
use Wob\Library\Domain\Service\ContentHasher;
use Wob\Library\Domain\ValueObject\AssetId;
use Wob\Library\Domain\ValueObject\ChapterId;
use Wob\Library\Domain\ValueObject\ContentHash;
use Wob\Library\Domain\ValueObject\LevelId;
use Wob\Library\Domain\ValueObject\MapNode;
use Wob\Library\Domain\ValueObject\NodeId;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Shared\Domain\AggregateRoot;
use Wob\Shared\Domain\Exception\AccessDenied;
use Wob\Shared\Domain\Exception\InvariantViolation;
use Wob\Shared\Domain\Exception\NotFound;

/**
 * A story with its chapters and levels — the aggregate.
 *
 * The client keeps stories, chapters, levels and assets as four flat lists
 * joined by id, which is the right shape for localStorage and the wrong shape
 * for a server. Flat lists cannot express the rules that actually hold, and
 * every one of those rules spans more than one list:
 *
 *   - a chapter map may only pin levels of its own story;
 *   - a path may only join levels that are on that same map;
 *   - an exit may only lead to a chapter of this story;
 *   - deleting a chapter drops the levels no other chapter uses, and quietly
 *     clears the exits that used to lead into it.
 *
 * Written as free functions over flat tables, those rules have nowhere to live
 * and get re-implemented, differently, in every endpoint that touches content.
 * Written here, they are enforced by the only object that is allowed to change
 * any of it.
 *
 * The boundary is also the transaction boundary and the locking boundary: one
 * story is loaded, changed and saved as a unit, with one version number. Two
 * editors on two different stories never contend.
 */
final class Story extends AggregateRoot
{
    /** Version 0 means "not yet in the database". */
    public const NEW = 0;

    /** @var array<string, Chapter> keyed by chapter id, insertion order is chapter order */
    private array $chapters = [];

    /** @var array<string, Level> keyed by level id */
    private array $levels = [];

    /**
     * @param list<Chapter> $chapters
     * @param list<Level>   $levels
     * @param list<AssetId> $hot
     */
    public function __construct(
        public readonly StoryId $id,
        public readonly OwnerId $ownerId,
        private string $title,
        private string $cover,
        array $chapters = [],
        array $levels = [],
        private array $hot = [],
        private int $version = self::NEW,

        // The point a player starts on — a place, not a chapter: which chapter
        // it sits in is something the point already knows. Structure, so it
        // reaches contentHash(); covers and films do not.
        private ?string $startNodeId = null,

        // Plays once, before the story does. The only film that runs before
        // play rather than after it, which is what keeps a new player from
        // waiting twice over.
        private string $intro = '',
    ) {
        $this->rename($title);

        foreach ($levels as $level) {
            $this->levels[$level->id->value] = $level;
        }

        foreach ($chapters as $chapter) {
            $this->chapters[$chapter->id->value] = $chapter;
        }

        // Rows written before stories had a start, and stories built with a
        // chapter already in hand, both land here with nothing chosen. The
        // first chapter takes it, which is exactly what the old
        // order-implies-the-opening behaviour did — so nothing that already
        // exists changes meaning, it just says out loud what it always meant.
        $this->startNodeId ??= $this->firstNodeId();

        $this->assertReferencesResolve();
    }

    // ---- reading ------------------------------------------------------------

    public function title(): string
    {
        return $this->title;
    }

    public function cover(): string
    {
        return $this->cover;
    }

    public function version(): int
    {
        return $this->version;
    }

    /** @return list<Chapter> */
    public function chapters(): array
    {
        return array_values($this->chapters);
    }

    /** @return list<Level> */
    public function levels(): array
    {
        return array_values($this->levels);
    }

    /** @return list<AssetId> */
    public function hot(): array
    {
        return $this->hot;
    }

    public function chapter(ChapterId $id): Chapter
    {
        return $this->chapters[$id->value] ?? throw NotFound::of("Chapter", $id->value);
    }

    public function level(LevelId $id): Level
    {
        return $this->levels[$id->value] ?? throw NotFound::of("Level", $id->value);
    }

    public function isOwnedBy(OwnerId $ownerId): bool
    {
        return $this->ownerId->equals($ownerId);
    }

    /**
     * Kept although the repository already scopes every lookup to an owner.
     *
     * Belt and braces on purpose: this is the invariant, and the repository is
     * one implementation of it. A future read path that forgets the owner clause
     * should fail loudly here rather than quietly hand over someone else's work.
     */
    public function assertOwnedBy(OwnerId $ownerId): void
    {
        if (!$this->isOwnedBy($ownerId)) {
            throw AccessDenied::of("Story", $this->id->value);
        }
    }

    // ---- editing ------------------------------------------------------------

    public function rename(string $title): void
    {
        $title = trim($title);

        if ($title === "" || mb_strlen($title) > 200) {
            throw InvariantViolation::because("Story title must be 1-200 characters");
        }

        $this->title = $title;
    }

    public function setCover(string $cover): void
    {
        if (mb_strlen($cover) > 2000) {
            throw InvariantViolation::because("Story cover is too long");
        }

        $this->cover = $cover;
    }

    /** @param list<AssetId> $hot */
    public function setHot(array $hot): void
    {
        $this->hot = array_values($hot);
    }

    public function startNodeId(): ?string
    {
        return $this->startNodeId;
    }

    public function intro(): string
    {
        return $this->intro;
    }

    public function setIntro(string $intro): void
    {
        $this->intro = $intro;
    }

    /** The chapter holding a point, which is how a point names its chapter. */
    public function chapterOf(NodeId $nodeId): ?ChapterId
    {
        foreach ($this->chapters as $chapter) {
            foreach ($chapter->nodes() as $node) {
                if ($node->id->equals($nodeId)) {
                    return $chapter->id;
                }
            }
        }

        return null;
    }

    private function firstNodeId(): ?string
    {
        foreach ($this->chapters as $chapter) {
            foreach ($chapter->nodes() as $node) {
                return $node->id->value;
            }
        }

        return null;
    }

    /**
     * Choose where the story begins.
     *
     * Chapter order used to imply this, which was never quite a decision: the
     * order is the unlock order, and the chapter a player meets first is a
     * separate thing an author should be able to say out loud. Naming a chapter
     * that is not in this story would leave a story that opens onto nothing, so
     * it is refused here rather than discovered on the way in.
     */
    public function startOn(?string $nodeId): void
    {
        if ($nodeId !== null && $this->chapterOf(new NodeId($nodeId)) === null) {
            throw InvariantViolation::because(
                sprintf('Point %s is not in this story, so it cannot start it', $nodeId),
            );
        }

        $this->startNodeId = $nodeId;
    }

    /**
     * Save a chapter's map, then check it against the whole story.
     *
     * The chapter validates what it can see on its own — ids unique, points
     * well formed — but a link may land in another chapter now, so whether it
     * resolves is a question only the story can answer. Rejecting restores the
     * old map: a half-applied map is worse than a refused one, because it looks
     * fine and gates the wrong places.
     *
     * @param list<MapNode> $nodes
     */
    public function replaceChapterMap(ChapterId $chapterId, array $nodes): void
    {
        $chapter = $this->chapter($chapterId);
        $before = $chapter->nodes();

        $chapter->replaceMap($nodes);

        try {
            $this->assertReferencesResolve();
            $this->assertNoCycles();
            $this->assertChaptersAreNotRevisited();
        } catch (InvariantViolation $e) {
            $chapter->replaceMap($before);

            throw $e;
        }
    }

    /**
     * Точка переехала.
     *
     * Одна из мелких операций, которыми теперь правится черновик. Раньше на её
     * месте уезжала вся карта главы разом, и потому любые две правки в одной
     * главе спорили между собой — даже если двигали разные точки. Спор разнимали
     * номером версии, а номер приходилось угадывать клиенту: отсюда и конфликты
     * на создании уровня, которое ни с чем конфликтовать не может.
     *
     * Проверять маршруты здесь незачем: переезд точки не меняет ни одной связи.
     */
    public function moveNode(ChapterId $chapterId, NodeId $nodeId, float $x, float $y): void
    {
        $chapter = $this->chapter($chapterId);
        $node = $chapter->node($nodeId) ?? throw InvariantViolation::because(
            sprintf("Chapter %s has no point %s", $chapterId->value, $nodeId->value),
        );

        $chapter->replaceNode($node->movedTo($x, $y));
    }

    /** Что игрок видит в этом месте: имя, картинка, ролик после победы. */
    public function describeNode(
        ChapterId $chapterId,
        NodeId $nodeId,
        string $name,
        string $image,
        string $outro,
    ): void {
        $chapter = $this->chapter($chapterId);
        $node = $chapter->node($nodeId) ?? throw InvariantViolation::because(
            sprintf("Chapter %s has no point %s", $chapterId->value, $nodeId->value),
        );

        $chapter->replaceNode($node->describedAs($name, $image, $outro));
    }

    /**
     * Провести связь.
     *
     * Здесь маршруты проверяются обязательно: связь — единственное, что может
     * замкнуть историю в кольцо или вернуть путь в покинутую главу. Зато
     * проверка теперь стоит там, где нарушение и рождается, а не на сохранении
     * всей карты, где о причине приходилось догадываться.
     */
    public function linkNodes(NodeId $from, NodeId $to): void
    {
        $chapterId = $this->chapterOf($from) ?? throw InvariantViolation::because(
            sprintf("Story %s has no point %s", $this->id->value, $from->value),
        );

        if ($this->chapterOf($to) === null) {
            throw InvariantViolation::because(
                sprintf("Story %s has no point %s", $this->id->value, $to->value),
            );
        }

        $chapter = $this->chapter($chapterId);
        $node = $chapter->node($from);
        $before = $node;

        $chapter->replaceNode($node->leadingTo($to));

        try {
            $this->assertRoutesAreSound();
        } catch (InvariantViolation $e) {
            $chapter->replaceNode($before);

            throw $e;
        }
    }

    /** Снять связь. Снять можно всегда: убирая дорогу, кольца не создашь. */
    public function unlinkNodes(NodeId $from, NodeId $to): void
    {
        $chapterId = $this->chapterOf($from) ?? throw InvariantViolation::because(
            sprintf("Story %s has no point %s", $this->id->value, $from->value),
        );

        $chapter = $this->chapter($chapterId);
        $chapter->replaceNode($chapter->node($from)->notLeadingTo($to));
    }

    /** Как выглядит глава: название и фон, на котором стоят точки. */
    public function describeChapter(ChapterId $chapterId, string $title, string $image, string $map = ""): void
    {
        $chapter = $this->chapter($chapterId);
        $chapter->rename($title);
        $chapter->setImage($image);
        $chapter->setMap($map);
    }

    public function addChapter(Chapter $chapter): void
    {
        if (isset($this->chapters[$chapter->id->value])) {
            throw InvariantViolation::because(
                sprintf("Chapter %s is already in this story", $chapter->id->value),
            );
        }

        $this->chapters[$chapter->id->value] = $chapter;

        // The first point to arrive takes the slot. An author who never thinks
        // about this still gets a story that opens somewhere sensible, and one
        // who does can say otherwise at any time. A chapter with no points yet
        // claims nothing — there is no place to stand in it.
        $this->startNodeId ??= $this->firstNodeId();

        $this->assertReferencesResolve();
        $this->assertNoCycles();
        $this->assertChaptersAreNotRevisited();
    }

    /**
     * Chapter order is the unlock order: a chapter opens when the previous one
     * is finished, so reordering changes what the player may play next.
     *
     * @param list<ChapterId> $order
     */
    public function reorderChapters(array $order): void
    {
        if (count($order) !== count($this->chapters)) {
            throw InvariantViolation::because("Chapter order must list every chapter exactly once");
        }

        $reordered = [];

        foreach ($order as $id) {
            if (!isset($this->chapters[$id->value]) || isset($reordered[$id->value])) {
                throw InvariantViolation::because("Chapter order must list every chapter exactly once");
            }

            $reordered[$id->value] = $this->chapters[$id->value];
        }

        $this->chapters = $reordered;
    }

    /**
     * Removing a chapter is the one operation with real consequences elsewhere,
     * and the client already knows what they are:
     *
     *  - levels that no surviving chapter pins are gone with it, because nothing
     *    can reach them any more and they would sit in the library forever;
     *  - exits that led into this chapter are cleared everywhere. Leaving one
     *    would show a node that looks like a way onward and would let a chapter
     *    count as finished through a road that does not exist.
     */
    public function removeChapter(ChapterId $id): void
    {
        $chapter = $this->chapter($id);
        unset($this->chapters[$id->value]);

        $gone = $chapter->nodeIds();

        // Losing the opening point must not leave the story pointing at a place
        // that is gone. The first surviving point inherits it.
        foreach ($gone as $nodeId) {
            if ($this->startNodeId === $nodeId->value) {
                $this->startNodeId = $this->firstNodeId();

                break;
            }
        }

        foreach ($this->chapters as $other) {
            $other->forgetLinksTo(...$gone);
        }

        $orphans = [];

        foreach ($chapter->levelIds() as $levelId) {
            if (!$this->isPinnedAnywhere($levelId)) {
                unset($this->levels[$levelId->value]);
                $orphans[] = $levelId;
            }
        }

        if ($orphans !== []) {
            $this->recordThat(new LevelsDiscarded($this->id, $orphans));
        }
    }

    /**
     * A level is created into a chapter: a level nobody can reach is not content,
     * it is litter. The map position comes from the caller because it is a
     * presentation decision, but the pinning happens here so that "every level
     * belongs to at least one map" holds by construction.
     */
    /**
     * Уровень без места на карте.
     *
     * Автор делает уровень в панели, наполняет его и только потом решает, в
     * какую главу положить. До этого момента точки у него нет — и раньше такого
     * состояния здесь не было вовсе, поэтому редактор сохранял уровень, которого
     * на сервере не существует, и получал 404 в бесконечном цикле.
     *
     * Уровень принадлежит истории, а не главе (levels.story_id), так что место
     * для него тут было с самого начала — не хватало только способа его создать.
     */
    public function addSpareLevel(Level $level): void
    {
        if (isset($this->levels[$level->id->value])) {
            throw InvariantViolation::because(sprintf("Level %s is already in this story", $level->id->value));
        }

        $this->levels[$level->id->value] = $level;
    }

    public function addLevel(ChapterId $chapterId, Level $level, MapNode $node): void
    {
        if (isset($this->levels[$level->id->value])) {
            throw InvariantViolation::because(sprintf("Level %s is already in this story", $level->id->value));
        }

        if (!$node->levelId->equals($level->id)) {
            throw InvariantViolation::because("The map node must point at the level being added");
        }

        $this->levels[$level->id->value] = $level;
        $this->chapter($chapterId)->pin($node);

        // The very first place to exist in this story opens it. Stories are
        // built a level at a time, so this is usually where the slot is
        // claimed, not in the constructor.
        $this->startNodeId ??= $node->id->value;
    }

    /**
     * Unpin from one chapter; delete outright only if no other chapter still
     * shows it. Shared levels are legitimate — a hub level can sit on two maps.
     */
    public function removeLevel(ChapterId $chapterId, LevelId $levelId): void
    {
        $this->chapter($chapterId)->unpin($levelId);

        if (!$this->isPinnedAnywhere($levelId)) {
            unset($this->levels[$levelId->value]);
            $this->recordThat(new LevelsDiscarded($this->id, [$levelId]));
        }
    }

    public function delete(): void
    {
        $this->recordThat(new StoryDeleted($this->id, $this->ownerId, array_map(
            static fn (Level $l): LevelId => $l->id,
            array_values($this->levels),
        )));
    }

    /**
     * Проверить маршруты истории целиком.
     *
     * Существует ради тех, кто собирает историю не по одной правке, а сразу
     * готовой, — прежде всего ради импорта файла. Правки из редактора идут
     * через addChapter() и replaceChapterMap(), и там проверка стоит сама;
     * файл же приходит снаружи и минует их оба.
     *
     * Отдельным методом, а не в конструкторе, и это осознанно. Конструктор —
     * ещё и путь чтения из базы: заставь его проверять, и история, у которой
     * маршруты уже сломаны, перестанет открываться вовсе, то есть станет
     * непочинимой. Так что проверяют те, кто принимает содержимое снаружи, а
     * не те, кто достаёт своё.
     */
    public function assertRoutesAreSound(): void
    {
        $this->assertNoCycles();
        $this->assertChaptersAreNotRevisited();
        $this->assertNoLevelRepeatsOnAPath();
    }

    /**
     * Один уровень не встречается дважды на одном пути.
     *
     * Точка показывает уровень, а не заводит его, поэтому один уровень законно
     * стоит в нескольких местах истории — но в разных ветвях, а не подряд на
     * одной дороге. Пройти его дважды за одно прохождение значит переиграть уже
     * сыгранное: счёт пройденного собьётся, а игрок решит, что заблудился.
     *
     * Проверяется по пути, как и возврат в главу: смотрим, можно ли из точки
     * дойти до другой точки того же уровня. Если можно — значит есть
     * прохождение, где он встретится дважды.
     */
    private function assertNoLevelRepeatsOnAPath(): void
    {
        /** @var array<string, string> $levelOf */
        $levelOf = [];
        /** @var array<string, list<string>> $edges */
        $edges = [];

        foreach ($this->chapters as $chapter) {
            foreach ($chapter->nodes() as $node) {
                $levelOf[$node->id->value] = $node->levelId->value;
                $edges[$node->id->value] = array_map(
                    static fn (NodeId $child): string => $child->value,
                    $node->next,
                );
            }
        }

        foreach ($edges as $start => $_) {
            $level = $levelOf[$start];
            $seen = [$start => true];
            $stack = $edges[$start];

            while ($stack !== []) {
                $at = array_pop($stack);

                if (isset($seen[$at])) {
                    continue;
                }

                $seen[$at] = true;

                if (($levelOf[$at] ?? null) === $level) {
                    throw InvariantViolation::because(sprintf(
                        "Level %s is met twice on one path, at points %s and %s",
                        $level,
                        $start,
                        $at,
                    ));
                }

                foreach ($edges[$at] ?? [] as $next) {
                    $stack[] = $next;
                }
            }
        }
    }

    // ---- versioning ---------------------------------------------------------

    /**
     * Optimistic locking. The editor is offline-first, so two devices can hold
     * the same story; last-write-wins would silently eat an afternoon of level
     * design. The client sends the version it loaded, and a stale write is
     * refused rather than applied.
     */
    /**
     * Сверка версии черновика. Больше не вызывается ниоткуда.
     *
     * Осталась как след решения, которое оказалось неверным, и как объяснение,
     * почему его сняли. Версия черновика росла на каждой записи, и клиент был
     * обязан носить её с собой и угадывать текущую. Защищала она от одного:
     * двух рук, сохраняющих карту главы целиком. Но карта целиком — сама по
     * себе неверная единица записи, а раз её не стало, то и защищать нечего:
     * мелкие операции над разными точками не спорят, а над одной сходятся к
     * последней.
     *
     * Ценой были отказы там, где спора быть не может в принципе. Создание
     * уровня ничего не затирает, оно добавляет, — и всё равно получало 409,
     * стоило очереди сдвинуть номер между чтением и отправкой.
     *
     * Номер, который что-то значит для автора, остался ровно один — номер
     * релиза.
     */
    public function expectVersion(int $expected): void
    {
        if ($expected !== $this->version) {
            throw new \Wob\Shared\Domain\Exception\ConcurrentModification($expected, $this->version);
        }
    }

    public function bumpVersion(): int
    {
        return ++$this->version;
    }

    // ---- content fingerprint ------------------------------------------------

    /**
     * The hasher is passed in rather than held, so the aggregate stays free of
     * dependencies and the fingerprint stays a pure function of content.
     */
    /**
     * The story's fingerprint.
     *
     * Titles land here — the story's own, and every chapter's — one level above
     * the thing they name. A chapter title is not part of what that chapter is
     * to play, so renaming it must not invalidate the records set on it; but it
     * is a real change to the story, and this is where it registers. The same
     * reasoning puts level names in the chapter fingerprint rather than the
     * level's.
     *
     * The effect is a rename that costs exactly what it should: the story has a
     * new version, the chapter and its levels do not, and nobody's times are
     * thrown away over a spelling correction.
     */
    public function contentHash(ContentHasher $hasher): ContentHash
    {
        return new ContentHash($hasher->hash([
            "id" => $this->id->value,
            "title" => $this->title,

            // Which chapter opens the story is content, not decoration: change
            // it and players meet a different story. Intro films and covers are
            // absent from here for the opposite reason.
            "start" => $this->startNodeId ?? "",

            "chapters" => array_map(
                fn (Chapter $c): string => $c->id->value
                    . ":" . $this->chapterHash($hasher, $c)->value
                    . ":" . $c->title(),
                array_values($this->chapters),
            ),
        ]));
    }

    public function chapterHash(ContentHasher $hasher, Chapter $chapter): ContentHash
    {
        $levelHashes = [];

        $levelNames = [];

        foreach ($chapter->levelIds() as $levelId) {
            $level = $this->levels[$levelId->value] ?? null;
            $levelHashes[$levelId->value] = $level === null ? "null" : $this->levelHash($hasher, $level)->value;
            $levelNames[$levelId->value] = $level?->name() ?? '';
        }

        return new ContentHash($hasher->hash($chapter->hashableContent($levelHashes, $levelNames)));
    }

    public function levelHash(ContentHasher $hasher, Level $level): ContentHash
    {
        return new ContentHash($hasher->hash($level->hashableContent()));
    }

    // ---- invariants ---------------------------------------------------------

    private function isPinnedAnywhere(LevelId $levelId): bool
    {
        foreach ($this->chapters as $chapter) {
            if ($chapter->holds($levelId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Связи не должны замыкаться в кольцо.
     *
     * Это не про опрятность графа, а про то, что кольцо запирает само себя.
     * Точка открывается, когда пройден хоть один из ведущих в неё родителей; у
     * каждой точки кольца родитель есть, и ни один из них не пройден и не
     * может быть пройден. Значит всё кольцо и всё, что растёт за ним,
     * недостижимо навсегда — при том, что история сохраняется, отдаётся и
     * рисуется как ни в чём не бывало.
     *
     * Проверяется по всей истории, а не внутри главы: связь свободно уходит на
     * соседнюю карту, и кольцо складывается из двух глав, в каждой из которых
     * по отдельности всё честно. Ровно поэтому проверка живёт здесь, а не в
     * Chapter — глава своих связей целиком не видит.
     *
     * Обход итеративный. Рекурсия читалась бы короче и упиралась бы в глубину
     * стека на длинной истории, а длинная история — обычный случай, а не
     * крайний.
     *
     * Клиент спрашивает о том же перед тем, как провести линию, и это не
     * дублирование: там вопрос задаётся, чтобы не дать нарисовать заведомо
     * мёртвую связь, здесь — чтобы её нельзя было записать в обход интерфейса.
     */
    private function assertNoCycles(): void
    {
        /** @var array<string, list<string>> $edges */
        $edges = [];

        foreach ($this->chapters as $chapter) {
            foreach ($chapter->nodes() as $node) {
                $edges[$node->id->value] = array_map(
                    static fn (NodeId $child): string => $child->value,
                    $node->next,
                );
            }
        }

        // 1 — точка лежит на текущем пути, 2 — точка и всё за ней уже проверены.
        $state = [];

        foreach (array_keys($edges) as $root) {
            if (isset($state[$root])) {
                continue;
            }

            $state[$root] = 1;
            $stack = [[$root, 0]];

            while ($stack !== []) {
                [$at, $i] = array_pop($stack);
                $children = $edges[$at] ?? [];

                if (!isset($children[$i])) {
                    $state[$at] = 2;

                    continue;
                }

                $stack[] = [$at, $i + 1];
                $child = $children[$i];

                if (($state[$child] ?? 0) === 1) {
                    throw InvariantViolation::because(sprintf(
                        "Point %s leads back to %s, so the story would close into a loop",
                        $at,
                        $child,
                    ));
                }

                if (!isset($state[$child])) {
                    $state[$child] = 1;
                    $stack[] = [$child, 0];
                }
            }
        }
    }

    /**
     * Выйдя из главы, путь не возвращается в неё.
     *
     * Внутри главы ходить можно как угодно: несколько точек подряд, развилки,
     * слияния. Запрещено ровно «вышел и вернулся» — ch1 → ch2 → ch1. Глава в
     * таком пути перестаёт быть его отрезком и становится двумя разными местами
     * под одним именем, а весь учёт хода игры ведётся по главам: какая открыта,
     * какая пройдена, какая следующая. Глава, пройденная наполовину, потом
     * покинутая, потом открытая заново, делает бессмысленным каждый из этих
     * трёх ответов — и делает это молча, потому что сохраняется и рисуется она
     * при этом безупречно.
     *
     * Проверяется по пути, а не по «графу глав». Кольцо в графе глав можно
     * получить рёбрами из кусков главы, между которыми нет ни одного настоящего
     * пути; запрещать такое значило бы отвергать связи, по которым никто никогда
     * не пройдёт. Здесь вопрос ставится ровно так, как он звучит: выйдя вот этой
     * связью, можно ли добрести обратно.
     *
     * Опирается на ацикличность, проверенную выше: без неё обход был бы
     * бесконечным. Порядок вызовов поэтому не случаен.
     */
    private function assertChaptersAreNotRevisited(): void
    {
        /** @var array<string, string> $chapterOf */
        $chapterOf = [];
        /** @var array<string, list<string>> $edges */
        $edges = [];

        foreach ($this->chapters as $chapter) {
            foreach ($chapter->nodes() as $node) {
                $chapterOf[$node->id->value] = $chapter->id->value;
                $edges[$node->id->value] = array_map(
                    static fn (NodeId $child): string => $child->value,
                    $node->next,
                );
            }
        }

        foreach ($edges as $from => $children) {
            $home = $chapterOf[$from];

            foreach ($children as $child) {
                // Связь внутри главы никуда не выводит, значит и возвращать ей
                // неоткуда.
                if (($chapterOf[$child] ?? $home) === $home) {
                    continue;
                }

                $seen = [$child => true];
                $stack = [$child];

                while ($stack !== []) {
                    $at = array_pop($stack);

                    if ($chapterOf[$at] === $home) {
                        throw InvariantViolation::because(sprintf(
                            "Point %s leaves chapter %s, and the road on from it comes back into that chapter",
                            $from,
                            $home,
                        ));
                    }

                    foreach ($edges[$at] ?? [] as $next) {
                        if (isset($seen[$next])) {
                            continue;
                        }

                        $seen[$next] = true;
                        $stack[] = $next;
                    }
                }
            }
        }
    }

    private function assertReferencesResolve(): void
    {
        foreach ($this->chapters as $chapter) {
            foreach ($chapter->nodes() as $node) {
                if (!isset($this->levels[$node->levelId->value])) {
                    throw InvariantViolation::because(sprintf(
                        "Chapter %s pins level %s, which is not in this story",
                        $chapter->id->value,
                        $node->levelId->value,
                    ));
                }

                foreach ($node->next as $child) {
                    // A link may cross into another chapter or stay put, so the
                    // whole story is the scope here — a chapter on its own can
                    // no longer tell whether a link resolves.
                    if ($this->chapterOf($child) === null) {
                        throw InvariantViolation::because(sprintf(
                            "Point %s leads to point %s, which is not in this story",
                            $node->id->value,
                            $child->value,
                        ));
                    }
                }
            }
        }
    }
}
