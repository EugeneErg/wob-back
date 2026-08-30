<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Wob\Shared\Presentation\Http\DomainExceptionMapper;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The API is stateful. The session cookie is the only credential this
        // application issues, so the api group carries the cookie and session
        // middleware unconditionally.
        //
        // Sanctum's statefulApi() was the obvious choice and is the wrong one
        // here: it turns statefulness on only for requests whose Origin matches
        // a configured list, which is what you want when the same API also
        // serves bearer tokens. This one never does. Making it conditional only
        // creates a way for authentication to be silently off — a missing
        // header and every request is anonymous, with nothing to say why.
        $middleware->api(prepend: [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,

            // Cookies travel by themselves, so a cookie-authenticated API needs
            // CSRF protection. Renamed from VerifyCsrfToken in Laravel 13, and
            // it now also checks the Sec-Fetch-Site header.
            PreventRequestForgery::class,
        ]);

        // The sign-in call is the one request that cannot carry a CSRF token:
        // it is what establishes the session the token would come from. It is
        // safe to exempt because it proves nothing about the caller's cookies —
        // it is authenticated by a Google credential the attacker cannot mint.
        $middleware->validateCsrfTokens(except: ['api/auth/google']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Domain failures are answered in one place. Without this, a broken
        // invariant surfaces as a 500 and pages somebody at night over a typo
        // in a level name.
        $exceptions->render(static fn (Throwable $e) => app(DomainExceptionMapper::class)->render($e));
    })
    ->create();
