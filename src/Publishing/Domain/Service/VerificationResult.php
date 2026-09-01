<?php

declare(strict_types=1);

namespace Wob\Publishing\Domain\Service;

/**
 * What the replay found.
 *
 * Three outcomes, not two, and the third is the one that matters. "Verified"
 * and "rejected" are answers about the run; "unavailable" is the admission that
 * we could not get an answer at all — the verifier was down, or the reply was
 * unreadable. Collapsing that into "rejected" would throw away honest records
 * whenever a service restarted, and collapsing it into "verified" would let a
 * cheat pass by taking the checker offline.
 */
final readonly class VerificationResult
{
    private function __construct(
        public bool $decided,
        public bool $genuine,
        public ?string $reason,
        public ?int $actualTicks,
    ) {
    }

    public static function genuine(int $ticks): self
    {
        return new self(true, true, null, $ticks);
    }

    public static function rejected(string $reason, ?int $actualTicks = null): self
    {
        return new self(true, false, $reason, $actualTicks);
    }

    public static function unavailable(string $why): self
    {
        return new self(false, false, $why, null);
    }
}
