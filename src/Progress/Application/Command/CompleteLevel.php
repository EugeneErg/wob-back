<?php

declare(strict_types=1);

namespace Wob\Progress\Application\Command;

final readonly class CompleteLevel
{
    public function __construct(
        public string $userId,
        public string $levelId,
        /** The run this belongs to, or null when the player is not using slots. */
        public ?string $slotId = null,
    )
    {
    }
}
