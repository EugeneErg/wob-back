<?php

declare(strict_types=1);

namespace Wob\Tests\Unit\Identity;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;
use Wob\Identity\Application\Exception\AuthenticationFailed;
use Wob\Identity\Infrastructure\Google\GoogleIdTokenVerifier;

/**
 * The verifier against tokens this test signs itself.
 *
 * Signing for real, rather than faking the JWT library, because the checks worth
 * testing here are the ones that only matter when the signature is valid: a
 * perfectly signed token issued to a different application, or by a different
 * issuer, must still be refused. A mocked decoder cannot tell you that.
 */
final class GoogleIdTokenVerifierTest extends TestCase
{
    private const CLIENT_ID = '1234.apps.googleusercontent.com';
    private const KID = 'test-key-1';

    private \OpenSSLAsymmetricKey $privateKey;

    /** @var array<string, mixed> */
    private array $jwks;

    protected function setUp(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        self::assertNotFalse($key);
        $this->privateKey = $key;

        $details = openssl_pkey_get_details($key);
        self::assertNotFalse($details);

        $this->jwks = ['keys' => [[
            'kty' => 'RSA',
            'kid' => self::KID,
            'use' => 'sig',
            'alg' => 'RS256',
            'n' => $this->base64url($details['rsa']['n']),
            'e' => $this->base64url($details['rsa']['e']),
        ]]];
    }

    public function testAGenuineTokenIsAccepted(): void
    {
        $identity = $this->verifier()->verify($this->token());

        self::assertSame('sub-123', $identity->subject);
        self::assertSame('author@example.com', $identity->email);
        self::assertTrue($identity->emailVerified);
        self::assertSame('Author', $identity->name);
    }

    /**
     * The check people skip. This token is signed by the right key and is
     * entirely valid — it was just issued to somebody else's application, and
     * accepting it would let anyone with their own Google app sign in as anyone
     * here.
     */
    public function testAValidTokenForAnotherApplicationIsRefused(): void
    {
        $this->expectException(AuthenticationFailed::class);

        $this->verifier()->verify($this->token(['aud' => 'someone-else.apps.googleusercontent.com']));
    }

    public function testAValidTokenFromAnotherIssuerIsRefused(): void
    {
        $this->expectException(AuthenticationFailed::class);

        $this->verifier()->verify($this->token(['iss' => 'https://evil.example.com']));
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $this->expectException(AuthenticationFailed::class);

        $this->verifier()->verify($this->token(['exp' => time() - 60, 'iat' => time() - 3600]));
    }

    public function testATokenSignedByTheWrongKeyIsRefused(): void
    {
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($other);

        $forged = JWT::encode($this->claims(), $other, 'RS256', self::KID);

        $this->expectException(AuthenticationFailed::class);

        // Two JWKS responses: the verifier is allowed one refresh in case the
        // failure was a rotation rather than a forgery.
        $this->verifier([$this->jwksResponse(), $this->jwksResponse()])->verify($forged);
    }

    /**
     * Google rotates signing keys every few hours. A token signed with a key
     * minted since the last fetch must not look like a forgery, or sign-in
     * breaks for everybody on every rotation.
     */
    public function testAnUnknownKeyIdTriggersOneRefresh(): void
    {
        $stale = ['keys' => []];

        $verifier = $this->verifier([
            new Response(200, [], json_encode($stale, JSON_THROW_ON_ERROR)),
            $this->jwksResponse(),
        ]);

        self::assertSame('sub-123', $verifier->verify($this->token())->subject);
    }

    public function testTheKeySetIsFetchedOnceAndThenCached(): void
    {
        // One response for two verifications: a second fetch would exhaust the
        // queue and throw.
        $verifier = $this->verifier([$this->jwksResponse()]);

        self::assertSame('sub-123', $verifier->verify($this->token())->subject);
        self::assertSame('sub-123', $verifier->verify($this->token())->subject);
    }

    public function testAnUnconfiguredServerRefusesRatherThanPretending(): void
    {
        $verifier = new GoogleIdTokenVerifier(
            '',
            $this->httpClient([$this->jwksResponse()]),
            new HttpFactory(),
            new Repository(new ArrayStore()),
        );

        $this->expectException(AuthenticationFailed::class);

        $verifier->verify($this->token());
    }

    /** @param list<Response>|null $responses */
    private function verifier(?array $responses = null): GoogleIdTokenVerifier
    {
        return new GoogleIdTokenVerifier(
            self::CLIENT_ID,
            $this->httpClient($responses ?? [$this->jwksResponse()]),
            new HttpFactory(),
            new Repository(new ArrayStore()),
            // Named, not positional. The logger sits between the cache and the
            // JWKS url, and passing the url fifth quietly aimed these tests at
            // the real googleapis.com — they would have passed or failed on
            // whether the sandbox had a network.
            jwksUri: 'https://example.test/certs',
        );
    }

    /** @param list<Response> $responses */
    private function httpClient(array $responses): Client
    {
        return new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
    }

    private function jwksResponse(): Response
    {
        return new Response(200, [], json_encode($this->jwks, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $overrides */
    private function token(array $overrides = []): string
    {
        return JWT::encode([...$this->claims(), ...$overrides], $this->privateKey, 'RS256', self::KID);
    }

    /** @return array<string, mixed> */
    private function claims(): array
    {
        return [
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'sub' => 'sub-123',
            'email' => 'author@example.com',
            'email_verified' => true,
            'name' => 'Author',
            'picture' => 'https://example.com/a.png',
            'iat' => time(),
            'exp' => time() + 3600,
        ];
    }

    private function base64url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
