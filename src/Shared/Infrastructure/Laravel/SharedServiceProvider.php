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
        // PSR-18 and PSR-17, bound to Guzzle.
        //
        // Shared rather than owned by whichever context happened to need them
        // first. They lived in Identity while only Google sign-in made outbound
        // calls; the moment run verification started making them too, the
        // binding depended on provider registration order — and Publishing,
        // registered later, found nothing there.
        $this->app->singleton(\Psr\Http\Client\ClientInterface::class, static fn (): \GuzzleHttp\Client => new \GuzzleHttp\Client([
            // Nothing outbound may hang a request or a queue worker.
            'timeout' => 15,
            'connect_timeout' => 3,
        ]));

        $this->app->singleton(
            \Psr\Http\Message\RequestFactoryInterface::class,
            static fn (): \GuzzleHttp\Psr7\HttpFactory => new \GuzzleHttp\Psr7\HttpFactory(),
        );

        $this->app->singleton(
            \Psr\Http\Message\StreamFactoryInterface::class,
            static fn (): \GuzzleHttp\Psr7\HttpFactory => new \GuzzleHttp\Psr7\HttpFactory(),
        );

        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(DomainEventBus::class, LaravelDomainEventBus::class);
    }
}
