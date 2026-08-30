<?php

declare(strict_types=1);

namespace Wob\Identity\Infrastructure\Laravel;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Wob\Identity\Application\Port\GoogleIdentityVerifier;
use Wob\Identity\Domain\Repository\UserRepository;
use Wob\Identity\Infrastructure\Google\GoogleIdTokenVerifier;
use Wob\Identity\Infrastructure\Laravel\Auth\IdentityUserProvider;
use Wob\Identity\Infrastructure\Persistence\Database\DatabaseUserRepository;
use Wob\Shared\Domain\DomainEventBus;

final class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Teaches the session guard how to turn the id in a cookie back into a
        // user, without the domain model becoming an Eloquent model to do it.
        $this->app->make("auth")->provider(
            "identity",
            fn (Container $c): IdentityUserProvider => new IdentityUserProvider($c->make(UserRepository::class)),
        );
    }

    public function register(): void
    {
        $this->app->singleton(UserRepository::class, static fn (Container $c): UserRepository => new DatabaseUserRepository(
            $c->make("db")->connection(),
            $c->make(DomainEventBus::class),
        ));

        $this->app->singleton(GoogleIdentityVerifier::class, static fn (Container $c): GoogleIdentityVerifier => new GoogleIdTokenVerifier(
            (string) config("wob.google.client_id"),
            $c->make(\GuzzleHttp\Client::class),
            $c->make(\GuzzleHttp\Psr7\HttpFactory::class),
            $c->make("cache")->store(),
        ));
    }
}
