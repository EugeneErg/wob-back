<?php

declare(strict_types=1);

namespace Wob\Progress\Application\Command;

final readonly class CompleteLevel
{
    public function __construct(public string $userId, public string $levelId)
    {
    }
}
