<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Wob\Library\Domain\Model\Chapter;
use Wob\Library\Domain\Model\Level;
use Wob\Library\Domain\Model\Story;
use Wob\Library\Domain\Repository\StoryRepository;
use Wob\Library\Domain\ValueObject\ChapterId;
use Wob\Library\Domain\ValueObject\Dimensions;
use Wob\Library\Domain\ValueObject\EntityPlacement;
use Wob\Library\Domain\ValueObject\Gravity;
use Wob\Library\Domain\ValueObject\LevelId;
use Wob\Library\Domain\ValueObject\MapNode;
use Wob\Library\Domain\ValueObject\NodeId;
use Wob\Library\Domain\ValueObject\OwnerId;
use Wob\Library\Domain\ValueObject\StoryId;
use Wob\Publishing\Application\Command\PublishRelease;
use Wob\Publishing\Application\Handler\PublishReleaseHandler;
use Wob\Publishing\Application\Handler\ReevaluateCanonHandler;
use Wob\Publishing\Domain\Service\CanonPolicy;
use Wob\Publishing\Domain\ValueObject\ReleaseId;

/**
 * Готовая история: три главы по три уровня, выпущенная и коронованная.
 *
 * Нужна, чтобы в пустой базе было что открыть и во что играть, не проходя
 * весь путь автора руками.
 *
 * Корона здесь не проставляется в базу напрямую, и это главное решение в этом
 * файле. Канон зарабатывается: автор публикует, сам проходит каждый уровень,
 * затем сто пятьдесят игроков доходят до концовки и ставят оценки, и лишь потом
 * спрашивается ReevaluateCanonHandler. Сид, который просто пишет
 * canonical_release_id, создал бы историю, невозможную в жизни, — и первым же
 * несоответствием сбил бы с толку того, кто станет разбираться, почему у
 * настоящих историй корона не появляется.
 *
 * Числа берутся из CanonPolicy, а не повторяются здесь: поменяется правило —
 * сид пойдёт за ним, а не начнёт тихо выдавать некоронуемую историю.
 */
final class CanonStorySeeder extends Seeder
{
    private const AUTHOR_EMAIL = 'author@wob.local';
    private const STORY_ID = 'story-demo';

    public function run(): void
    {
        $authorId = $this->author();

        // Сид можно запускать повторно. Прежняя демо-история сносится целиком:
        // дописывать поверх значило бы получить историю, зависящую от того,
        // сколько раз до этого запускали сид.
        DB::table('stories')->where('public_id', self::STORY_ID)->delete();

        $this->stories()->save($this->buildStory($authorId));

        $release = app(PublishReleaseHandler::class)(
            new PublishRelease($authorId, self::STORY_ID),
        );

        // Автор проходит каждый уровень: без этого релиз не виден никому.
        $this->clearEveryLevel($authorId, $release->id->value);

        $this->crowd($release->id->value);

        $crowned = app(ReevaluateCanonHandler::class)(new ReleaseId($release->id->value));

        $this->command?->info($crowned
            ? 'История «Три двери» выпущена и коронована.'
            : 'История выпущена, но корону не получила — проверьте CanonPolicy.');

        $this->command?->info('Автор: ' . self::AUTHOR_EMAIL);
    }

    /**
     * Три главы по три уровня, связанные в дерево с развилкой.
     *
     * Форма выбрана не для красоты: три на три — это ровно нижняя граница
     * канона, так что сид заодно показывает, как выглядит история, едва до неё
     * дотягивающая. Развилка нужна, чтобы в истории было что выбирать, а слияние
     * в финале — чтобы обе дороги куда-то вели.
     */
    private function buildStory(string $authorId): Story
    {
        $levels = [];
        $chapters = [];

        $plan = [
            'ch-door' => ['title' => 'Первая дверь', 'levels' => ['lvl-hall', 'lvl-stairs', 'lvl-attic']],
            'ch-yard' => ['title' => 'Двор', 'levels' => ['lvl-gate', 'lvl-well', 'lvl-shed']],
            'ch-road' => ['title' => 'Дорога', 'levels' => ['lvl-bridge', 'lvl-hill', 'lvl-end']],
        ];

        foreach ($plan as $chapterId => $spec) {
            $nodes = [];
            $names = $spec['levels'];

            foreach ($names as $i => $levelId) {
                $levels[] = $this->level($levelId, $i);

                // Внутри главы уровни идут цепочкой; последний ведёт дальше,
                // и эту связь дописываем ниже, когда все главы уже названы.
                $next = isset($names[$i + 1]) ? [new NodeId('nd-' . $names[$i + 1])] : [];

                $nodes[] = new MapNode(
                    new NodeId('nd-' . $levelId),
                    new LevelId($levelId),
                    18.0 + $i * 32,
                    30.0 + ($i % 2) * 30,
                    $next,
                    name: ucfirst(str_replace('lvl-', '', $levelId)),
                );
            }

            $chapters[$chapterId] = new Chapter(
                new ChapterId($chapterId),
                $spec['title'],
                'linear-gradient(160deg,#1d3040,#0f1a20)',
                $nodes,
            );
        }

        $story = new Story(
            new StoryId(self::STORY_ID),
            new OwnerId($authorId),
            'Три двери',
            'linear-gradient(140deg,#2b4a5c,#16242b)',
            array_values($chapters),
            $levels,
        );

        // Главы соединяются в цепочку: конец первой ведёт в начало второй.
        // Через домен, а не сборкой узлов руками, — чтобы сид проходил ровно те
        // проверки, что и живой редактор: кольца, возврат в главу, повтор
        // уровня на пути.
        $story->linkNodes(new NodeId('nd-lvl-attic'), new NodeId('nd-lvl-gate'));
        $story->linkNodes(new NodeId('nd-lvl-shed'), new NodeId('nd-lvl-bridge'));
        $story->startOn('nd-lvl-hall');

        return $story;
    }

    /**
     * Уровень, в который действительно можно играть.
     *
     * Раньше здесь лежали два прямоугольника «земли», и это был не уровень, а
     * пустой экран: у terrain нет ширины и высоты, у него полигон из точек, а
     * шара и лунки не было вовсе — сажать в лунку было нечего и некуда.
     *
     * Минимум, при котором уровень проходится: земля, по которой катится шар,
     * сам шар и лунка, засчитываемая в цель. Цель — один шар.
     */
    private function level(string $id, int $i): Level
    {
        $ground = new \stdClass();
        $ground->points = [
            [80, 700 + $i * 20],
            [760, 760],
            [1520, 700 - $i * 20],
            [1520, 880],
            [80, 880],
        ];
        $ground->smoothness = 0.35;
        $ground->fill = '#2a3326';
        $ground->edge = '#66804f';

        /*
         * Данные пишутся полностью, включая всё, что редактор проставил бы сам.
         *
         * Умолчания сущности применяются только при создании новой; то, что
         * пришло с сервера, движок берёт как есть. Неполная запись поэтому
         * оборачивается не «частично настроенным шаром», а NaN в координатах и
         * пустым экраном.
         */
        $ball = (object) [
            'x' => 220.0, 'y' => 520.0,
            'r' => 13, 'builtR' => 13, 'sleepR' => 13,
            'mass' => 1, 'builtMass' => 1, 'sleepMass' => 1,
            'opacity' => 1, 'anchorable' => true, 'asleep' => false,
            'minLinks' => 2, 'maxLinks' => 3,
            'range' => 165, 'jump' => 470, 'speed' => 95, 'dropMax' => 190,
            'color' => '#e2704a', 'linkColor' => '#f0b48c',
        ];

        $hole = (object) [
            'x' => 1340.0, 'y' => (float) (690 - $i * 20),
            'r' => 19, 'depth' => 26, 'counts' => true,
            'signal' => '', 'color' => '#4a5560', 'glow' => '#8fb36a',
        ];

        return new Level(
            new LevelId($id),
            ucfirst(str_replace('lvl-', '', $id)),
            new Dimensions(1600, 900),
            new Gravity(0, 1800),
            1,
            [
                new EntityPlacement($id . '-ground', 'terrain', $ground),
                new EntityPlacement($id . '-ball', 'game-ball', $ball),
                new EntityPlacement($id . '-hole', 'hole', $hole),
            ],
        );
    }

    /** @return list<string> */
    private function levelIds(): array
    {
        return [
            'lvl-hall', 'lvl-stairs', 'lvl-attic',
            'lvl-gate', 'lvl-well', 'lvl-shed',
            'lvl-bridge', 'lvl-hill', 'lvl-end',
        ];
    }

    private function clearEveryLevel(string $userId, string $releaseId): void
    {
        foreach ($this->levelIds() as $levelId) {
            $this->completion($userId, $levelId);
        }

        DB::table('releases')->where('id', $releaseId)->update([
            'author_cleared_at' => now(),
        ]);
    }

    /**
     * Толпа, которой хватает на корону.
     *
     * Каждый доходит до конца и ставит оценку: канон требует и кворум
     * прошедших, и средний балл, и одно без другого ничего не даёт.
     */
    private function crowd(string $releaseId): void
    {
        $levels = $this->levelIds();

        for ($i = 0; $i < CanonPolicy::QUORUM; $i++) {
            $playerId = $this->user("player{$i}@wob.local", "Игрок {$i}");

            foreach ($levels as $levelId) {
                $this->completion($playerId, $levelId);
            }

            // Одна строка на игрока: кворум считает не прохождения, а людей,
            // прошедших свой маршрут целиком.
            DB::table('release_completions')->insert([
                'id' => (string) Uuid::uuid4(),
                'release_id' => $releaseId,
                'player_id' => $playerId,
                'levels_finished' => count($levels),
                'levels_on_route' => count($levels),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Оценка выше требуемого среднего, но не десятка: десятки у всех
            // выглядят как накрутка, а сид не должен учить плохому.
            DB::table('votes')->insert([
                'id' => (string) Uuid::uuid4(),
                'release_id' => $releaseId,
                'level_public_id' => 'lvl-end',
                'voter_id' => $playerId,
                'rating' => 9,
                'carried_over' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function completion(string $userId, string $levelId): void
    {
        DB::table('level_completions')->insertOrIgnore([
            'id' => (string) Uuid::uuid4(),
            'user_id' => $userId,
            'level_public_id' => $levelId,
            'first_completed_at' => now(),
            'last_completed_at' => now(),
            'completions' => 1,
        ]);
    }

    private function author(): string
    {
        return $this->user(self::AUTHOR_EMAIL, 'Автор');
    }

    private function user(string $email, string $name): string
    {
        $existing = DB::table('users')->where('email', $email)->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $id = (string) Uuid::uuid4();

        DB::table('users')->insert([
            'id' => $id,
            'google_sub' => 'seed-' . $email,
            'email' => $email,
            'display_name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function stories(): StoryRepository
    {
        return app(StoryRepository::class);
    }
}
