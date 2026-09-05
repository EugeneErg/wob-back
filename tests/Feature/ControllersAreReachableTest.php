<?php

declare(strict_types=1);

namespace Wob\Tests\Feature;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Wob\Tests\TestCase;

/**
 * У каждого метода контроллера есть маршрут.
 *
 * Соседний RoutesResolveTest смотрит в другую сторону — что маршрут ведёт в
 * существующий метод, — и этого мало. Три крупных дефекта подряд были ровно
 * обратными: метод написан, обработчик написан, а двери наружу нет. Так
 * молчали публикация релиза, постановка точки на карту и форк: возможности
 * просто не существовало, притом что код для неё был готов и покрыт тестами,
 * звавшими обработчик напрямую.
 *
 * Проверка грубая — сопоставление имён с текстом routes/api.php, — и это
 * осознанно: она должна ловить забытое звено, а не разбирать маршрутизацию.
 */
final class ControllersAreReachableTest extends TestCase
{
    public function testEveryControllerMethodHasARoute(): void
    {
        $routes = (string) file_get_contents(base_path('routes/api.php'));
        $unreachable = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path('src'), FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!str_ends_with((string) $file->getFilename(), 'Controller.php')) {
                continue;
            }

            $source = (string) file_get_contents((string) $file->getPathname());

            if (!preg_match('/class\s+(\w+)/', $source, $found)) {
                continue;
            }

            $class = $found[1];

            preg_match_all('/public function (\w+)\(/', $source, $methods);

            foreach ($methods[1] as $method) {
                if (in_array($method, ['__construct', '__invoke'], true)) {
                    continue;
                }

                $mentioned = preg_quote($class, '/') . '::class,\s*["\']' . preg_quote($method, '/') . '["\']';

                if (preg_match('/' . $mentioned . '/', $routes) !== 1) {
                    $unreachable[] = "{$class}::{$method}";
                }
            }
        }

        self::assertSame(
            [],
            $unreachable,
            "написаны, но недостижимы снаружи:\n" . implode("\n", $unreachable),
        );
    }
}
