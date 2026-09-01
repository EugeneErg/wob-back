<?php

declare(strict_types=1);

namespace Wob\Publishing\Infrastructure\Verification;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Throwable;
use Wob\Publishing\Domain\Model\SpeedrunRecord;
use Wob\Publishing\Domain\Service\RunVerifier;
use Wob\Publishing\Domain\Service\VerificationResult;
use Wob\Publishing\Domain\ValueObject\ContentSnapshot;

/**
 * Talks to the Node service that owns the physics.
 *
 * The level travels with the request rather than being fetched by the verifier.
 * That keeps the service stateless and, more to the point, keeps the question
 * unambiguous: the run is checked against the exact frozen bytes this release
 * contains, not against whatever the verifier would have looked up for itself.
 */
final readonly class HttpRunVerifier implements RunVerifier
{
    public function __construct(
        private string $endpoint,
        private ClientInterface $http,
        private RequestFactoryInterface $requests,
        private StreamFactoryInterface $streams,
    ) {
    }

    public function verify(SpeedrunRecord $record, ContentSnapshot $content): VerificationResult
    {
        if ($this->endpoint === '') {
            return VerificationResult::unavailable('no verifier configured');
        }

        $level = $record->targetPublicId === null ? null : $content->level($record->targetPublicId);

        if ($level === null) {
            // Not a service failure: the release genuinely does not contain what
            // this run claims to be against.
            return VerificationResult::rejected('unknown-level');
        }

        $payload = json_encode([
            'level' => $level,
            'seed' => $record->seed,
            'input' => $record->input,
            'ticks' => $record->ticks,
            'rulesVersion' => $record->rulesVersion,
        ], JSON_THROW_ON_ERROR);

        try {
            $request = $this->requests->createRequest('POST', $this->endpoint)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream($payload));

            $response = $this->http->sendRequest($request);
        } catch (Throwable $e) {
            return VerificationResult::unavailable($e->getMessage());
        }

        if ($response->getStatusCode() !== 200) {
            return VerificationResult::unavailable('verifier returned ' . $response->getStatusCode());
        }

        try {
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return VerificationResult::unavailable('unreadable reply');
        }

        if (($body['ok'] ?? false) === true) {
            return VerificationResult::genuine((int) $body['ticks']);
        }

        // The verifier distinguishes "this run is wrong" from "I cannot check
        // this run", and so must we. A record whose engine version is not
        // available is not a forgery — it is a run from a build we no longer
        // carry, and deleting it would punish the runner for our housekeeping.
        if (($body['undecided'] ?? false) === true) {
            return VerificationResult::unavailable((string) ($body['reason'] ?? 'undecided'));
        }

        return VerificationResult::rejected(
            (string) ($body['reason'] ?? 'rejected'),
            isset($body['ticks']) ? (int) $body['ticks'] : null,
        );
    }
}
