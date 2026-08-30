<?php

declare(strict_types=1);

namespace Wob\Shared\Infrastructure\Laravel;

use Illuminate\Support\ServiceProvider;
use Wob\Shared\Domain\Clock;
use Wob\Shared\Domain\DomainEventBus;
use Wob\Shared\Infrastructure\SystemClock;

final class SharedServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(DomainEventBus::class, LaravelDomainEventBus::class);
    }
}
