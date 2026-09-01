<?php

declare(strict_types=1);

namespace Wob\Identity\Infrastructure\Google;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Throwable;
use Wob\Identity\Application\DTO\GoogleIdentity;
use Wob\Identity\Application\Exception\AuthenticationFailed;
use Wob\Identity\Application\Port\GoogleIdentityVerifier;

/**
 * Verifies a Google ID token locally against Google's published signing keys.
 *
 * Locally, not by calling the tokeninfo endpoint: that would put a network round
 * trip to a third party in the path of every sign-in and make Google's latency
 * ours.
 *
 * The signature alone is not enough, and the two extra checks are the ones
 * people skip. The audience must be OUR client id — a validly signed token
 * issued to some other application is still a validly signed token, and
 * accepting it lets anyone with their own Google app sign in here as whoever
 * they like. And the issuer must be Google. Expiry the library checks itself.
 *
 * The key cache is written by hand rather than using Firebase's CachedKeySet,
 * for a boring reason worth recording: CachedKeySet wants a PSR-6 cache item
 * pool and Laravel's cache is PSR-16. Adapting one to the other would pull in
 * another dependency to do less than the twenty lines below — which also make
 * the key rotation behaviour visible instead of implied.
 */
final readonly class GoogleIdTokenVerifier implements GoogleIdentityVerifier
{
    private const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];
    private const CACHE_KEY = 'google.jwks';

    public function __construct(
        private string $clientId,
        private ClientInterface $http,
        private RequestFactoryInterface $requests,
        private Cache $cache,
        /**
         * Where the real reason goes.
         *
         * The response says one bland thing on purpose — an exact reason is a
         * free oracle for whoever is probing — so without somewhere to write
         * the truth, a refused sign-in is undiagnosable. That is not
         * hypothetical: a PHP install with no CA bundle refuses every token
         * with a message about verification, and the actual cause is a cURL
         * error nobody can see.
         */
        private ?LoggerInterface $log = null,
        private string $jwksUri = 'https://www.googleapis.com/oauth2/v3/certs',
        private int $cacheSeconds = 3600,
    ) {
    }

    public function verify(string $credential): GoogleIdentity
    {
        if ($this->clientId === '') {
            throw AuthenticationFailed::because('Google sign-in is not configured on this server');
        }

        $claims = $this->decode($credential);

        if (!in_array($claims->iss ?? '', self::ISSUERS, true)) {
            $this->refuse('issuer is not Google', ['iss' => $claims->iss ?? null]);
        }

        $audience = $claims->aud ?? null;

        if (!is_string($audience) || !hash_equals($this->clientId, $audience)) {
            // This one gets its own message even in the response. It is not a
            // forgery, it is the two halves of the app holding different client
            // ids — and saying so saves an afternoon.
            $this->log?->warning('Google sign-in rejected: wrong audience', [
                'expected' => $this->clientId,
                'received' => is_string($audience) ? $audience : gettype($audience),
            ]);

            throw AuthenticationFailed::because('This sign-in was issued for a different application');
        }

        $subject = $claims->sub ?? null;
        $email = $claims->email ?? null;

        if (!is_string($subject) || $subject === '' || !is_string($email) || $email === '') {
            $this->refuse('token carries no subject or email');
        }

        return new GoogleIdentity(
            $subject,
            $email,
            (bool) ($claims->email_verified ?? false),
            is_string($claims->name ?? null) ? $claims->name : '',
            is_string($claims->picture ?? null) ? $claims->picture : null,
        );
    }

    private function decode(string $credential): object
    {
        // A minute of slack on iat and nbf. Not laxity: the check is against
        // OUR clock, and a container whose time has drifted a few seconds
        // rejects every genuine token with an error that says nothing about
        // clocks. Expiry is still enforced.
        JWT::$leeway = 60;

        try {
            return JWT::decode($credential, $this->keys(refresh: false));
        } catch (Throwable $first) {
            // Google rotates signing keys every few hours. A token signed with a
            // key minted since the last fetch is not a forgery, it is a cache
            // miss, so one refresh is allowed before giving up. Without this,
            // sign-in breaks for everyone on every rotation until the cache
            // happens to expire.
            try {
                return JWT::decode($credential, $this->keys(refresh: true));
            } catch (Throwable $second) {
                // The caller is told nothing: an exact reason is a free oracle
                // for forging the next attempt. The operator is told
                // everything, because a failure nobody can diagnose is a
                // failure that never gets fixed.
                $this->refuse('token did not verify', [
                    'firstAttempt' => $first::class . ': ' . $first->getMessage(),
                    'afterKeyRefresh' => $second::class . ': ' . $second->getMessage(),
                ]);
            }
        }
    }

    /** @return array<string, Key> */
    private function keys(bool $refresh): array
    {
        if ($refresh) {
            $this->cache->forget(self::CACHE_KEY);
        }

        // The raw JSON is cached, not the parsed keys: a parsed Key holds an
        // OpenSSLAsymmetricKey, which cannot be serialised, so any cache driver
        // that survives a restart would fail on the way back out.
        $json = $this->cache->remember(
            self::CACHE_KEY,
            $this->cacheSeconds,
            fn (): string => $this->fetchJwks(),
        );

        /** @var array<string, mixed> $jwks */
        $jwks = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return JWK::parseKeySet($jwks);
    }

    /**
     * Refuse: loudly in the log, blandly in the response.
     *
     * @param array<string, mixed> $context
     *
     * @throws AuthenticationFailed
     */
    private function refuse(string $reason, array $context = []): never
    {
        $this->log?->warning('Google sign-in rejected: ' . $reason, $context);

        throw AuthenticationFailed::because('Google sign-in could not be verified');
    }

    private function fetchJwks(): string
    {
        $response = $this->http->sendRequest($this->requests->createRequest('GET', $this->jwksUri));

        if ($response->getStatusCode() !== 200) {
            $this->log?->error('Could not fetch Google signing keys', [
                'uri' => $this->jwksUri,
                'status' => $response->getStatusCode(),
            ]);

            throw AuthenticationFailed::because('Google sign-in is temporarily unavailable');
        }

        return (string) $response->getBody();
    }
}
