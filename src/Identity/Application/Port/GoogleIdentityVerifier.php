<?php

declare(strict_types=1);

namespace Wob\Identity\Application\Port;

use Wob\Identity\Application\DTO\GoogleIdentity;

/**
 * Turns whatever Google handed the browser into a verified identity, or refuses.
 *
 * A port, so the application layer never learns which flow is in use. Today it
 * is an ID token from Google Identity Services; if the game later needs Drive
 * access it becomes an authorisation code exchange, and only the adapter behind
 * this interface changes.
 */
interface GoogleIdentityVerifier
{
    /** @throws \Wob\Identity\Application\Exception\AuthenticationFailed */
    public function verify(string $credential): GoogleIdentity;
}
