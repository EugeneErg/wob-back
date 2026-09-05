<?php

declare(strict_types=1);

namespace Wob\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Wob\Tests\TestCase;

/**
 * Каждый маршрут указывает на существующий метод.
 *
 * Написано после того, как POST /points полгода отвечал пятисоткой: маршрут
 * ссылался на LevelController::pin, команда и обработчик были готовы, а метода
 * не было. Ни один тест этого не заметил, потому что все звали обработчик
 * напрямую, минуя маршрутизацию. Не заметил и phpstan: маршрут — это массив
 * [Класс::class, "метод"], и связь между ними он не проверяет.
 *
 * Сигнал всё же был — «свойство $pin никогда не читается», — и его проглядели
 * среди прочих замечаний. Эта проверка не полагается на внимательность.
 */
final class RoutesResolveTest extends TestCase
{
    public function testEveryRoutePointsAtAMethodThatExists(): void
    {
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction('uses');

            if (!is_string($action) || !str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);

            if (!class_exists($class) || !method_exists($class, $method)) {
                $missing[] = sprintf('%s -> %s@%s', $route->uri(), $class, $method);
            }
        }

        self::assertSame([], $missing, "маршруты ведут в никуда:\n" . implode("\n", $missing));
    }
}
