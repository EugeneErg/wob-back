<?php

declare(strict_types=1);

namespace Wob\Library\Infrastructure\Persistence\Database;

use Illuminate\Database\ConnectionInterface;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Domain\Repository\ForkOverrideRepository;
use Wob\Publishing\Domain\Repository\ReleaseRepository;
use Wob\Publishing\Domain\ValueObject\ReleaseId;
use Wob\Library\Application\Query\LibraryReadModel;

final readonly class DatabaseLibraryReadModel implements LibraryReadModel
{
    public function __construct(
        private ConnectionInterface $db,
        private ReleaseRepository $releases,
        private ForkOverrideRepository $overrides,
    ) {
    }

    public function shelfOf(string $ownerId): array
    {
        // Summaries only. A shelf with fifty stories would otherwise ship every
        // entity of every level just to draw fifty covers.
        $stories = $this->db->table("stories")
            ->where("owner_id", $ownerId)
            ->orderBy("created_at")
            ->get()
            ->map(fn (object $s): array => [
                "id" => $s->public_id,
                "title" => $s->title,
                "cover" => $s->cover,
                "backdrop" => self::backdropOf($s),
                "startNodeId" => $s->start_node_id,
                "intro" => $s->intro,
                "hot" => $this->decode($s->hot),
                "hash" => $s->content_hash,
                "version" => (int) $s->version,
                "chapters" => $this->db->table("chapters")
                    ->where("story_id", $s->id)
                    ->orderBy("position")
                    ->pluck("public_id")
                    ->all(),
                "updatedAt" => $s->updated_at,
            ])
            ->all();

        $assets = $this->db->table("assets")
            ->where("owner_id", $ownerId)
            ->orderBy("created_at")
            ->get()
            ->map(fn (object $a): array => [
                "id" => $a->public_id,
                "title" => $a->title,
                "entities" => $this->decode($a->entities),
            ])
            ->all();

        return ["stories" => $stories, "assets" => $assets];
    }

    public function story(string $storyId, string $ownerId): ?array
    {
        $story = $this->db->table("stories")
            ->where("public_id", $storyId)
            ->where("owner_id", $ownerId)
            ->first();

        if ($story === null) {
            return null;
        }

        $chapters = $this->db->table("chapters")
            ->where("story_id", $story->id)
            ->orderBy("position")
            ->get()
            ->map(fn (object $c): array => [
                "id" => $c->public_id,
                "storyId" => $story->public_id,
                "title" => $c->title,
                "image" => $c->image,
                // Картинка главы на доске истории. С внутренностью главы не
                // связана — там у неё есть точки, тут она кнопка.
                "icon" => (string) ($c->icon ?? ''),
                "map" => $c->map,
                "canvas" => [
                    "x" => (float) $c->canvas_x, "y" => (float) $c->canvas_y,
                    "w" => (float) $c->canvas_w, "h" => (float) $c->canvas_h,
                ],
                "nodes" => $this->decode($c->nodes),
                "hot" => $this->decode($c->hot),
                "hash" => $c->content_hash,
            ])
            ->all();

        $levels = $this->db->table("levels")
            ->where("story_id", $story->id)
            ->orderBy("created_at")
            ->get()
            ->map($this->levelRow(...))
            ->all();

        /*
         * У форка своего содержимого нет, пока его не тронули.
         *
         * Копирование намеренно ленивое: главы и уровни появляются у копии по
         * мере того, как автор их правит, а до тех пор на все вопросы отвечает
         * базовый релиз. Но отвечать он должен и на этот вопрос тоже — иначе
         * человек, взявший историю себе, открывает редактор и видит пустоту,
         * хотя не удалял ничего.
         *
         * Наложение уже написано и используется при выдаче игрокам; здесь оно
         * просто применяется ещё и к чтению автором.
         */
        [$chapters, $levels] = $this->throughFork($story, $chapters, $levels);

        return [
            "id" => $story->public_id,
            "title" => $story->title,
            "cover" => $story->cover,
            "backdrop" => self::backdropOf($story),
            "startNodeId" => $story->start_node_id,
            "intro" => $story->intro,
            "hot" => $this->decode($story->hot),
            "hash" => $story->content_hash,
            "version" => (int) $story->version,
            "chapters" => $chapters,
            "levels" => $levels,
        ];
    }

    /**
     * Содержимое форка: базовый релиз, поверх которого легло изменённое.
     *
     * @param list<array<string, mixed>> $own
     * @param list<array<string, mixed>> $ownLevels
     *
     * @return array{0: list<mixed>, 1: list<mixed>}
     */
    private function throughFork(object $story, array $own, array $ownLevels): array
    {
        if (($story->forked_from_release_id ?? null) === null) {
            return [$own, $ownLevels];
        }

        $base = $this->releases->find(new ReleaseId((string) $story->forked_from_release_id));

        if ($base === null) {
            return [$own, $ownLevels];
        }

        $flat = $this->overrides
            ->overlayFor(new StoryId((string) $story->public_id), $base->content)
            ->flatten();

        /*
         * Наложение отдаёт объекты — это формат замороженного снимка. Остальная
         * библиотека читает главы и уровни как массивы, поэтому здесь их надо
         * привести, а не оставить как есть: иначе выгрузка падает на первом же
         * обращении к главе по ключу.
         */
        $asArrays = static fn (array $items): array => (array) json_decode(
            (string) json_encode($items),
            true,
        );

        /*
         * Снимок релиза не несёт полей, которые есть у черновика: hot — это
         * полка мастерской автора, а не часть выпущенной истории. Недостающее
         * достраивается пустым, иначе выгрузка спотыкается на первом же чтении.
         */
        $fill = static function (array $items) use ($asArrays): array {
            $out = [];

            foreach ($asArrays($items) as $item) {
                $out[] = $item + ['hot' => [], 'nodes' => [], 'entities' => []];
            }

            return $out;
        };

        return [$fill($flat->chapters), $fill($flat->levels)];
    }

    public function storyBundle(string $storyId, string $ownerId): ?array
    {
        $story = $this->story($storyId, $ownerId);

        if ($story === null) {
            return null;
        }

        return $this->bundle('story', [$story]);
    }

    public function libraryBundle(string $ownerId): array
    {
        $stories = [];

        foreach ($this->db->table('stories')->where('owner_id', $ownerId)->pluck('public_id') as $id) {
            $full = $this->story((string) $id, $ownerId);

            if ($full !== null) {
                $stories[] = $full;
            }
        }

        return $this->bundle('library', $stories);
    }

    /**
     * @param list<array<string, mixed>> $stories
     *
     * @return array<string, mixed>
     */
    /**
     * Все ассеты, названные где угодно внутри.
     *
     * @param array<string, bool> $out
     */
    private static function named(mixed $value, array &$out): void
    {
        if (!is_array($value)) {
            return;
        }

        if (is_string($value['asset'] ?? null) && $value['asset'] !== '') {
            $out[$value['asset']] = true;
        }

        foreach ($value as $item) {
            self::named($item, $out);
        }
    }

    /**
     * @param list<array<string, mixed>> $stories
     *
     * @return array{stories: list<array<string, mixed>>, assets: list<array<string, mixed>>}
     */
    private function bundle(string $kind, array $stories): array
    {
        $chapters = [];
        $levels = [];
        $storyEntries = [];
        $hot = [];

        foreach ($stories as $story) {
            $storyEntries[] = [
                'id' => $story['id'],
                'title' => $story['title'],
                'cover' => $story['cover'],
                'backdrop' => $story['backdrop'] ?? null,
                'chapters' => array_column($story['chapters'], 'id'),
                'hot' => $story['hot'],
            ];

            $hot = [...$hot, ...$story['hot']];

            foreach ($story['chapters'] as $chapter) {
                unset($chapter['hash']);
                $chapters[] = $chapter;
                $hot = [...$hot, ...$chapter['hot']];
            }

            foreach ($story['levels'] as $level) {
                unset($level['hash']);
                $levels[] = $level;
                $hot = [...$hot, ...$level['hot']];
            }
        }

        // Что уезжает вместе с файлом: всё, на что ссылаются уровни, плюс то,
        // что автор приколол к полке.
        //
        // Раньше здесь был только второй список, и рассуждение было верным для
        // прежнего ассета: он был штампом, его сущности ложились в уровень
        // копией, и «приколот» значило «может пригодиться». Ссылкой ассет стал
        // позже — теперь уровень называет его и хранит одни отличия, и без
        // названного его нельзя ни нарисовать, ни принять обратно.
        //
        // Видно это стало на импортированной истории: 58 уровней, 2706 шаров и
        // ноль ассетов в выгрузке. Файл выглядел целым.
        // Ссылка на ассет бывает не только у размещения, но и внутри данных:
        // шар с начинкой называет ассетом то, что из него родится. Ищется это
        // правилом о форме, а не по имени поля — объект со строковым `asset`
        // есть ссылка, где бы он ни лежал, — чтобы список полей, за которыми
        // надо следить, здесь не заводился вовсе.
        $named = [];

        foreach ($levels as $level) {
            self::named($level['entities'], $named);
        }

        // И замыкание: начинка бывает вложенной, а вложенный вид сам нигде не
        // поставлен. Без этого файл выглядел бы целым и молча терял то, что
        // появляется только из лопнувшего.
        $wanted = array_values(array_unique([...$hot, ...array_keys($named)]));
        $seen = [];

        while (true) {
            $fresh = array_values(array_diff($wanted, $seen));

            if ($fresh === []) {
                break;
            }

            $seen = [...$seen, ...$fresh];
            $rows = $this->db->table('assets')->whereIn('public_id', $fresh)->get();
            $more = [];

            foreach ($rows as $row) {
                self::named(json_decode((string) $row->entities, true), $more);
            }

            $wanted = array_values(array_unique([...$wanted, ...array_keys($more)]));
        }

        // Без отбора по владельцу, намеренно и по той же причине, что и в
        // AssetReferences: чужой ассет использовать можно, и разрешается ссылка
        // вопросом «существует ли», а не «чей он». Отбор по владельцу здесь
        // выкинул бы из файла ровно те ассеты, которых у получателя точно нет.
        $assets = $wanted === [] ? [] : $this->db->table('assets')
            ->whereIn('public_id', $wanted)
            ->get()
            ->map(fn (object $a): array => [
                'id' => $a->public_id,
                'title' => $a->title,
                'entities' => $this->decode($a->entities),
            ])
            ->all();

        return [
            'format' => 'goo-bundle',
            'version' => 1,
            'kind' => $kind,
            'stories' => $storyEntries,
            'chapters' => $chapters,
            'levels' => $levels,
            'assets' => $assets,
        ];
    }

    public function levelByHash(string $hash, string $ownerId): ?array
    {
        $row = $this->db->table("levels")
            ->join("stories", "stories.id", "=", "levels.story_id")
            ->where("stories.owner_id", $ownerId)
            ->where("levels.content_hash", $hash)
            ->select("levels.*")
            ->first();

        return $row === null ? null : $this->levelRow($row);
    }

    /** @return array<string, mixed> */
    private function levelRow(object $l): array
    {
        return [
            "id" => $l->public_id,
            "name" => $l->name,
            "image" => $l->image,
            "width" => (int) $l->width,
            "height" => (int) $l->height,
            "gravity" => $this->decode($l->gravity),
            "goal" => (int) $l->goal,
            "extra" => $l->extra === null ? null : json_decode((string) $l->extra, true),
            "entities" => $this->decode($l->entities),
            "hot" => $this->decode($l->hot),
            "hash" => $l->content_hash,
        ];
    }

    /**
     * Задник истории: картинка вместе с местом.
     *
     * Одним полем, а не пятью рядом. Пять полей на той стороне пришлось бы
     * собирать обратно, и каждый, кто их читает, решал бы сам, что значит
     * картинка без рамки. Здесь это решено один раз: нет картинки — нет задника.
     *
     * @return array{src: string, x: float, y: float, w: float, h: float}|null
     */
    private static function backdropOf(object $row): ?array
    {
        $src = trim((string) ($row->backdrop ?? ''));

        return $src === '' ? null : [
            'src' => $src,
            'x' => (float) ($row->backdrop_x ?? 0),
            'y' => (float) ($row->backdrop_y ?? 0),
            'w' => (float) ($row->backdrop_w ?? 0),
            'h' => (float) ($row->backdrop_h ?? 0),
        ];
    }

    private function decode(string $json): mixed
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}
