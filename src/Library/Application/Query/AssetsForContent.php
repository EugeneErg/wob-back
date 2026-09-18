<?php

declare(strict_types=1);

namespace Wob\Library\Application\Query;

use Wob\Library\Domain\Model\Asset;
use Wob\Library\Domain\Repository\AssetRepository;
use Wob\Library\Domain\ValueObject\AssetId;

/**
 * Ассеты, которыми играется это содержимое.
 *
 * ЗАЧЕМ
 *
 * Уровень не хранит, как выглядит шар: он называет ассет по имени, а выглядит
 * шар так, как сказано на полке. Полка авторская — и правильно, она его и
 * переиспользуется во всём, что он делает. Но выпущенную историю играет кто
 * угодно, и полки автора у него нет: уровень запускался пустым — шары на
 * месте, а выглядеть им нечем.
 *
 * ПОЧЕМУ ВМЕСТЕ С СОДЕРЖИМЫМ, А НЕ ОТДЕЛЬНЫМ ЗАПРОСОМ
 *
 * Ассеты — часть содержимого, а не справка о нём: уровень без них не
 * показать. Отдельный запрос означал бы, что клиент сперва получает то, что
 * нарисовать нельзя, потом разбирает его, потом идёт снова — и всё это между
 * нажатием и первым кадром.
 *
 * ПОЧЕМУ СВЯЗЫВАЕТ СЕРВЕР
 *
 * Ассет вправе называть другой ассет, и по набору это не редкость: восемь из
 * сорока девяти ссылаются дальше, а глубина доходит до трёх
 * (`undeletepill → fizz → spam`). Если бы список собирал клиент, число
 * обращений задавало бы чужое содержимое: автор, сделавший ассет на пять
 * этажей, добавил бы игроку пять походов на сервер, о которых ни игрок, ни
 * клиент заранее не знают.
 *
 * Серверу это ничего не стоит и ничего не нарушает: `asset` у размещения —
 * поле первого класса, с проверкой. Граница знания цела — сервер по-прежнему
 * не знает, что такое «грунт» или «мотор», и читает конверт, а не содержимое.
 */
final readonly class AssetsForContent
{
    public function __construct(private AssetRepository $assets)
    {
    }

    /**
     * @param list<object> $levels уровни снимка, как они лежат в выпуске
     *
     * @return list<Asset>
     */
    public function __invoke(array $levels): array
    {
        $named = [];

        foreach ($levels as $level) {
            foreach ($level->entities ?? [] as $entity) {
                $id = (string) ($entity->asset ?? '');

                if ($id !== '') {
                    $named[$id] = true;
                }
            }
        }

        return $this->close(array_keys($named));
    }

    /**
     * Замыкание по ссылкам: названные плюс всё, на что они ссылаются.
     *
     * Круг ссылок обход переживает: ассет, уже виденный, второй раз не
     * рассматривается. Запретить круги нельзя — выпуск с таким ассетом уже
     * существует, и играть его надо.
     *
     * @param list<string> $ids
     *
     * @return list<Asset>
     */
    private function close(array $ids): array
    {
        $out = [];
        $seen = [];
        $queue = $ids;

        while ($queue !== []) {
            $id = (string) array_shift($queue);

            if ($id === '' || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $asset = $this->assets->byId(new AssetId($id));

            if ($asset === null) {
                // Названо, но не найдено. Молча пропускаем: остальные ассеты
                // отдать всё равно надо, а падать из-за одного значит не
                // показать игроку ничего.
                continue;
            }

            $out[] = $asset;

            foreach ($asset->entities() as $entity) {
                if ($entity->asset !== null) {
                    $queue[] = $entity->asset;
                }
            }
        }

        return $out;
    }
}
