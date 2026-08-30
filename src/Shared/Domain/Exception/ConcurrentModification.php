<?php

declare(strict_types=1);

namespace Wob\Shared\Domain\Exception;

/**
 * The editor works offline and syncs later, so two tabs — or a phone and a
 * laptop — can hold the same story. Last write wins would silently destroy an
 * afternoon of level design, so writes carry the version they were based on.
 */
final class ConcurrentModification extends DomainException
{
    public function __construct(
        public readonly int $expectedVersion,
        public readonly int $actualVersion,
    ) {
        parent::__construct(sprintf(
            "The story changed since you loaded it (you have version %d, the server has %d)",
            $expectedVersion,
            $actualVersion,
        ));
    }
}
