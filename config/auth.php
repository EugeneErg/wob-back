<?php

declare(strict_types=1);

return [
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
