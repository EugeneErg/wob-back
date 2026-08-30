<?php

declare(strict_types=1);

namespace Wob\Shared\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Throwable;
use Wob\Identity\Application\Exception\AuthenticationFailed;
use Wob\Shared\Domain\Exception\AccessDenied;
use Wob\Shared\Domain\Exception\ConcurrentModification;
use Wob\Shared\Domain\Exception\InvariantViolation;
use Wob\Shared\Domain\Exception\NotFound;

/**
 * The one place that decides which domain failure is which HTTP status.
 *
 * Kept out of the controllers so that a use case can be driven from a console
 * command or a queue worker without dragging status codes along, and so that a
 * new endpoint cannot accidentally report a broken invariant as a 500 — which
 * would page somebody at night over a typo in a level name.
 */
final class DomainExceptionMapper
{
    public function render(Throwable $e): ?JsonResponse
    {
        return match (true) {
            $e instanceof NotFound => $this->json(404, "not_found", $e->getMessage()),
            $e instanceof AccessDenied => $this->json(403, "forbidden", $e->getMessage()),
            $e instanceof AuthenticationFailed => $this->json(401, "unauthenticated", $e->getMessage()),
            $e instanceof InvariantViolation => $this->json(422, "invalid", $e->getMessage()),

            // 409, not 422: nothing the caller sent is wrong. They simply have
            // an older picture of the world and need to reload before retrying.
            $e instanceof ConcurrentModification => $this->json(409, "conflict", $e->getMessage(), [
                "expectedVersion" => $e->expectedVersion,
                "actualVersion" => $e->actualVersion,
            ]),

            default => null,
        };
    }

    /** @param array<string, mixed> $extra */
    private function json(int $status, string $code, string $message, array $extra = []): JsonResponse
    {
        return new JsonResponse(["error" => ["code" => $code, "message" => $message, ...$extra]], $status);
    }
}
