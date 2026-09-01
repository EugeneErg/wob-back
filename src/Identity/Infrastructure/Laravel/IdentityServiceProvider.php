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
        if ($this->app->runningInConsole()) {
            $this->commands([\Wob\Identity\Presentation\Console\CheckGoogleSignIn::class]);
        }

        // Teaches the session guard how to turn the id in a cookie back into a
        // user, without the domain model becoming an Eloquent model to do it.
        $this->app->make('auth')->provider(
            'identity',
            fn (Container $c): IdentityUserProvider => new IdentityUserProvider($c->make(UserRepository::class)),
        );
    }

    public function register(): void
    {
        $this->app->singleton(UserRepository::class, static fn (Container $c): UserRepository => new DatabaseUserRepository(
            $c->make('db')->connection(),
            $c->make(DomainEventBus::class),
        ));

        // The HTTP client and factories come from the shared provider: sign-in
        // was the first thing to make outbound calls but is no longer the only
        // one, and a binding owned by whichever context happened to need it
        // first is a binding that depends on registration order.
        $this->app->singleton(GoogleIdentityVerifier::class, static fn (Container $c): GoogleIdentityVerifier => new GoogleIdTokenVerifier(
            (string) config('wob.google.client_id'),
            $c->make(\Psr\Http\Client\ClientInterface::class),
            $c->make(\Psr\Http\Message\RequestFactoryInterface::class),
            $c->make('cache')->store(),
            // Named, and it is not fussiness. This argument list has been
            // wrong twice: a logger was inserted before the JWKS url, and both
            // the wiring here and the tests went on passing things
            // positionally into the wrong slots. A logger where a string
            // belongs is a TypeError on the first sign-in; a url where a
            // logger belongs is silent.
            log: $c->make('log'),
        ));
    }
}
