<?php

declare(strict_types=1);

/*
 * One provider per bounded context. Nothing here knows what the others contain;
 * each wires its own repositories, ports and listeners.
 */

return [
    Wob\Shared\Infrastructure\Laravel\SharedServiceProvider::class,
    Wob\Identity\Infrastructure\Laravel\IdentityServiceProvider::class,
    Wob\Library\Infrastructure\Laravel\LibraryServiceProvider::class,
    Wob\Progress\Infrastructure\Laravel\ProgressServiceProvider::class,
];
