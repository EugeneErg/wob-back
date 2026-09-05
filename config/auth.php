<?php

declare(strict_types=1);

return [

    /*
     * Вход без Google для локальной разработки.
     *
     * Работает только вместе с APP_ENV=local: одного флага недостаточно
     * намеренно, потому что цена случайно включённого в проде — вход в любой
     * аккаунт по имени почты.
     */
    "dev_login" => env("WOB_DEV_LOGIN", false),

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        // Session-backed. The cookie is the credential; there is no token in a
        // response body for a script on the page to steal.
        'web' => [
            'driver' => 'session',
            'provider' => 'identity',
        ],
    ],

    'providers' => [
        // Not 'eloquent'. The domain User is plain PHP and has no business
        // being a model — see IdentityUserProvider.
        'identity' => [
            'driver' => 'identity',
        ],
    ],

    'passwords' => [],

    'password_timeout' => 10800,
];
