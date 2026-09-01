<?php

declare(strict_types=1);

return [
    "google" => [
        // The OAuth client id of the web application, from the Google Cloud
        // console. Also the audience every ID token is checked against — a
        // token issued to a different application must not be accepted here.
        "client_id" => env("GOOGLE_CLIENT_ID", ""),
    ],

    "verifier" => [
        // Where the Node service that replays runs is listening.
        //
        // Empty means runs are stored and never checked — and the leaderboard
        // then says "unverified" next to every time, which is the truth rather
        // than a degraded mode pretending to be fine.
        "endpoint" => env("WOB_VERIFIER_URL", ""),
    ],

    "library" => [
        // A level is a single JSON document and can be genuinely large once a
        // sand field has been dug through. Big enough to never bite an author,
        // small enough that a malicious body cannot exhaust memory.
        "max_level_bytes" => (int) env("WOB_MAX_LEVEL_BYTES", 4 * 1024 * 1024),
    ],
];
