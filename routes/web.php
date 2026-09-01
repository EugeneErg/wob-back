<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\JsonResponse;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Illuminate\Support\Facades\Route;

/*
 * There is no website here — the game is a separate Vue application and this is
 * its backend. The root exists anyway, and only to answer the question a person
 * actually has when they open it: is this thing running, and where is anything?
 *
 * A bare 404 answers neither. It looks identical whether the app booted fine and
 * simply has no home page, or the document root is pointing at the project
 * folder instead of public/ — which is the usual cause and costs an hour.
 */

Route::get('/', static fn (): JsonResponse => new JsonResponse([
    'service' => 'wob-backend',
    'status' => 'ok',
    'docs' => 'README.md',
    'endpoints' => [
        'health' => '/up',
        'signIn' => 'POST /api/auth/google',
        'currentUser' => 'GET /api/auth/me',
        'shelf' => 'GET /api/library',
        'import' => 'POST /api/library/import',
        'export' => 'GET /api/library/export',
    ],
    'note' => 'Everything except sign-in needs a session cookie. There is no browsable UI.',
], 200, [], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT))
    // No session, and therefore no database. An index page that dies together
    // with Postgres is useless exactly when somebody needs it: the whole point
    // of opening the root is to find out whether the thing is up.
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ]);
