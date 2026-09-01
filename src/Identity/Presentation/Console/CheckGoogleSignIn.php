<?php

declare(strict_types=1);

namespace Wob\Identity\Presentation\Console;

use Illuminate\Console\Command;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Throwable;

/**
 * Answers the question "why does sign-in say it could not be verified".
 *
 * The response body will never say, on purpose — an exact reason is a free
 * oracle for forging the next attempt. So the answer has to be available
 * somewhere else, and a command that checks each precondition in turn beats
 * reading a log looking for something that may not have been written.
 */
final class CheckGoogleSignIn extends Command
{
    protected $signature = 'wob:check-google {token? : an ID token to inspect, if you have one}';

    protected $description = 'Check that Google sign-in is configured and reachable';

    public function handle(ClientInterface $http, RequestFactoryInterface $requests): int
    {
        $clientId = (string) config('wob.google.client_id');
        $ok = true;

        $this->line('');

        if ($clientId === '') {
            $this->error('GOOGLE_CLIENT_ID is empty. Sign-in cannot work.');
            $this->line('  Set it in .env and run: php artisan config:clear');

            return self::FAILURE;
        }

        $this->info('client id: ' . $clientId);

        if (!str_ends_with($clientId, '.apps.googleusercontent.com')) {
            $this->warn('  That does not look like a Google client id.');
            $ok = false;
        }

        // Reaching Google is the other half. A server behind a proxy that
        // cannot fetch the key set fails every sign-in with a message about
        // verification, which points nowhere near the network.
        $uri = 'https://www.googleapis.com/oauth2/v3/certs';

        try {
            $response = $http->sendRequest($requests->createRequest('GET', $uri));
            $status = $response->getStatusCode();
            $keys = json_decode((string) $response->getBody(), true);
            $count = is_array($keys['keys'] ?? null) ? count($keys['keys']) : 0;

            if ($status === 200 && $count > 0) {
                $this->info(sprintf('signing keys: reachable, %d key(s)', $count));
            } else {
                $this->error(sprintf('signing keys: HTTP %d, %d key(s) — cannot verify anything', $status, $count));
                $ok = false;
            }
        } catch (Throwable $e) {
            $this->error('signing keys: unreachable — ' . $e->getMessage());
            $this->line('  This server must be able to reach ' . $uri);
            $this->hintForFailure($e->getMessage());
            $ok = false;
        }

        // Clock skew rejects genuine tokens with an error that says nothing
        // about clocks, so it is worth showing what this machine believes.
        $this->info('server time: ' . now()->toIso8601String());

        $token = $this->argument('token');

        if (is_string($token) && $token !== '') {
            $ok = $this->inspect($token, $clientId) && $ok;
        } else {
            $this->line('');
            $this->line('Tip: paste a token to compare its audience against the client id.');
            $this->line('It is the body of the POST to /api/auth/google in the Network tab.');
        }

        $this->line('');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Turn the common transport failures into something actionable.
     *
     * cURL 60 in particular is worth naming, because the first answer everyone
     * finds is to switch certificate verification off — and doing that here is
     * not a shortcut, it is the end of the security model. These keys are what
     * decide whether a token is genuine; accepting them over an unverified
     * connection means anyone able to sit between this server and Google can
     * hand it their own keys and sign in as anybody.
     */
    private function hintForFailure(string $message): void
    {
        if (!str_contains($message, 'certificate') && !str_contains($message, 'SSL')) {
            return;
        }

        $this->line('');
        $this->warn('  This is a PHP installation without a CA bundle, not a bug in the app.');
        $this->line('  1. Download https://curl.se/ca/cacert.pem');
        $this->line('  2. In php.ini (both CLI and the web SAPI) point at it:');
        $this->line('       curl.cainfo = "C:\\\\php\\\\extras\\\\ssl\\\\cacert.pem"');
        $this->line('       openssl.cafile = "C:\\\\php\\\\extras\\\\ssl\\\\cacert.pem"');
        $this->line('  3. Restart PHP, then run this command again.');
        $this->line('');
        $this->line('  Do NOT set verify => false on the HTTP client. These keys are');
        $this->line('  what decide whether a token is genuine.');
    }

    private function inspect(string $token, string $clientId): bool
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            $this->error('token: not a JWT (expected three dot-separated parts)');

            return false;
        }

        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), false), true);

        if (!is_array($claims)) {
            $this->error('token: payload is not readable');

            return false;
        }

        $this->line('');
        $this->info('token claims (NOT verified — this only compares them):');

        $aud = (string) ($claims['aud'] ?? '');
        $iss = (string) ($claims['iss'] ?? '');
        $exp = (int) ($claims['exp'] ?? 0);

        $this->line('  iss: ' . $iss);
        $this->line('  aud: ' . $aud);
        $this->line('  exp: ' . ($exp > 0 ? date('c', $exp) : '?'));

        $ok = true;

        if ($aud !== $clientId) {
            $this->error('  → audience does not match this server\'s client id.');
            $this->line('    The frontend and the backend were given different client ids.');
            $ok = false;
        }

        if (!in_array($iss, ['https://accounts.google.com', 'accounts.google.com'], true)) {
            $this->error('  → issuer is not Google.');
            $ok = false;
        }

        if ($exp > 0 && $exp < time()) {
            $this->warn('  → this token has expired (they last about an hour).');
        }

        return $ok;
    }
}
