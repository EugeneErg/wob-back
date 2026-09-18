<?php

declare(strict_types=1);

namespace Wob\Publishing\Infrastructure\Persistence\Database;

use Illuminate\Database\ConnectionInterface;
use Wob\Library\Application\Query\AssetsForContent;
use Wob\Library\Domain\Model\Asset;
use Wob\Publishing\Application\Query\CatalogReadModel;
use Wob\Publishing\Domain\Service\ContentGate;
use Wob\Publishing\Domain\ValueObject\ContentSnapshot;

final readonly class DatabaseCatalogReadModel implements CatalogReadModel
{
    public function __construct(
        private ConnectionInterface $db,
        private ContentGate $gate,
        private AssetsForContent $assets,
    ) {
    }

    public function canon(): array
    {
        $rows = $this->db->table('stories')
            ->join('releases', 'releases.id', '=', 'stories.canonical_release_id')
            ->whereNotNull('stories.canonical_release_id')
            ->orderBy('stories.canonical_since')
            ->select([
                'stories.public_id',
                'stories.title',
                'stories.cover',
                'stories.canonical_since',
                'releases.id as release_id',
                'releases.number as version',
                'releases.content_hash',
            ])
            ->get();

        return $rows->map($this->summary(...))->all();
    }

    public function published(): array
    {
        // Published but uncrowned: the release the author last cut, cleared by
        // them, on a story that has not made canon. These are what the votes
        // are actually for.
        $latest = $this->db->table('releases')
            ->select('story_id', $this->db->raw('MAX(number) as number'))
            ->whereNotNull('author_cleared_at')
            ->groupBy('story_id');

        $rows = $this->db->table('stories')
            ->joinSub($latest, 'newest', 'newest.story_id', '=', 'stories.id')
            ->join('releases', static function ($join): void {
                $join->on('releases.story_id', '=', 'stories.id')
                    ->on('releases.number', '=', 'newest.number');
            })
            ->whereNull('stories.canonical_release_id')
            ->orderByDesc('releases.created_at')
            ->select([
                'stories.public_id',
                'stories.title',
                'stories.cover',
                'releases.id as release_id',
                'releases.number as version',
                'releases.content_hash',
            ])
            ->get();

        return $rows->map($this->summary(...))->all();
    }

    public function forVisitor(): ?array
    {
        $first = $this->canon()[0] ?? null;

        if ($first === null) {
            return null;
        }

        return [...$first, 'preview' => true];
    }

    public function play(string $storyId, ?string $playerId): ?array
    {
        $story = $this->db->table('stories')
            ->where('public_id', $storyId)
            ->select(['id', 'public_id', 'title', 'canonical_release_id', 'start_node_id'])
            ->first();

        if ($story === null) {
            return null;
        }

        $row = $this->playableRelease($story);

        if ($row === null) {
            return null;
        }

        // Signed out, only the first canonical story is on offer at all, and
        // only a taste of it. Decided inside contentFor rather than here,
        // because the trimming and the eligibility are one decision, and one
        // decision belongs in one place: отдельная ручка уровня ходит тем же
        // путём, а гейт, к которому есть второй путь, — не гейт.
        $preview = $playerId === null;
        $content = $this->contentFor($storyId, $playerId);

        if ($content === null) {
            return null;
        }

        return [
            'id' => $story->public_id,
            'title' => $story->title,
            'releaseId' => $row->release_id,
            'version' => (int) $row->version,
            'hash' => $row->content_hash,
            'preview' => $preview,
            // Картинка позади глав. Из выпуска, а не у живой истории: главы
            // приезжают замороженными и стоят на заднике в его же единицах —
            // взяв живой, мы положили бы острова мимо планеты.
            'backdrop' => $content->backdrop,
            'chapters' => $content->chapters,
            // Уровни едут БЕЗ сущностей, а сущности — когда уровень откроют.
            //
            // Замерено на наборе оригинала: весь пакет истории 1858 КБ, из них
            // 1526 на сущности уровней и 264 на ассеты. Игроку перед картой
            // нужны имена, размеры и хеши — десять килобайт. Он тянул в сто
            // восемьдесят раз больше ради одного уровня, который откроет.
            //
            // Раньше так было нельзя: хеш главы считался по сущностям каждого
            // её уровня. Теперь уровень несёт проштампованный хеш, и карта
            // обходится им — см. `levelHash` на клиенте.
            'levels' => array_map(self::withoutEntities(...), $content->levels),
            // Откуда начинать. Берётся из замороженного снимка, а у релизов,
            // нарезанных до его появления, — из самой истории: иначе игрок
            // открывает их и не знает, с чего начать.
            'startNodeId' => $content->startNodeId ?? $story->start_node_id ?? null,
        ];
    }

    public function level(string $storyId, string $levelId, ?string $playerId): ?array
    {
        $content = $this->contentFor($storyId, $playerId);

        if ($content === null) {
            return null;
        }

        $level = $content->level($levelId);

        // Нет в обрезанном снимке — нет и ответа. Гостю отдаётся один уровень,
        // и запертый уровень, приехавший по отдельной ручке, заперт ровно так
        // же мало, как приехавший в общем пакете: сюда ведёт тот же путь и та
        // же обрезка, иначе это была бы дверь в обход двери.
        if ($level === null) {
            return null;
        }

        return [
            'level' => $level,
            // Ассеты этого уровня — вместе с ним, а не отдельной справкой:
            // уровень без них не показать, и второй круг между нажатием и
            // первым кадром игрок заметит.
            'assets' => array_map(
                static fn (Asset $a): array => [
                    'id' => $a->id->value,
                    'title' => $a->title(),
                    'types' => $a->types(),
                    'entities' => $a->entities(),
                ],
                ($this->assets)([$level]),
            ),
        ];
    }

    /**
     * Содержимое выпуска, как его вправе получить этот человек.
     *
     * Вынесено, потому что путь к содержимому один и тот же для всей истории и
     * для одного уровня: та же играбельная версия, та же обрезка для гостя. Две
     * копии этого пути означали бы два ответа на вопрос «что этому человеку
     * можно», и разошлись бы они молча.
     */
    private function contentFor(string $storyId, ?string $playerId): ?ContentSnapshot
    {
        $story = $this->db->table('stories')
            ->where('public_id', $storyId)
            ->select(['id', 'public_id', 'title', 'canonical_release_id', 'start_node_id'])
            ->first();

        if ($story === null) {
            return null;
        }

        $row = $this->playableRelease($story);

        if ($row === null) {
            return null;
        }

        $decoded = json_decode((string) $row->content, false, 512, JSON_THROW_ON_ERROR);
        $content = new ContentSnapshot(
            $decoded->chapters ?? [],
            $decoded->levels ?? [],
            $decoded->startNodeId ?? null,
            $decoded->backdrop ?? null,
        );

        if ($playerId !== null) {
            return $content;
        }

        $firstCanonical = $this->canon()[0] ?? null;

        if ($firstCanonical === null || $firstCanonical['id'] !== $storyId) {
            return null;
        }

        return $this->gate->forVisitor($content);
    }

    /** Уровень без сущностей: всё остальное при нём, включая штамп хеша. */
    private static function withoutEntities(object $level): object
    {
        $light = clone $level;
        unset($light->entities);

        return $light;
    }

    /**
     * Та версия истории, которую этот игрок и получит.
     *
     * Раньше здесь стоял join по canonical_release_id, и из-за него выдача
     * отвечала не на тот вопрос, на который отвечали списки. published() уже
     * показывал истории, выпущенные и пройденные автором, а играть их было
     * нельзя: без короны канона join не находил ничего и возвращался 404. То
     * есть автор выпускал историю, видел её на витрине и не мог открыть.
     *
     * Теперь правило одно и совпадает со списками: коронованная история играется
     * ровно в той версии, которую короновали, — иначе слово «канон» перестанет
     * что-либо значить, ведь корона выдана конкретному релизу и конкретному его
     * содержимому. Всё остальное играется в последнем релизе, который автор
     * выпустил и сам прошёл целиком.
     */
    private function playableRelease(object $story): ?object
    {
        $select = [
            'releases.id as release_id',
            'releases.number as version',
            'releases.content',
            'releases.content_hash',
        ];

        if ($story->canonical_release_id !== null) {
            return $this->db->table('releases')
                ->where('id', $story->canonical_release_id)
                ->select($select)
                ->first();
        }

        return $this->db->table('releases')
            ->where('story_id', $story->id)
            ->whereNotNull('author_cleared_at')
            ->orderByDesc('number')
            ->select($select)
            ->first();
    }

    /** @return array<string, mixed> */
    private function summary(object $row): array
    {
        return [
            'id' => $row->public_id,
            'title' => $row->title,
            'cover' => $row->cover,
            'releaseId' => $row->release_id,
            'version' => (int) $row->version,
            'hash' => $row->content_hash,
        ];
    }
}
