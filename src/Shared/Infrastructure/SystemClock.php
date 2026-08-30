<?php

declare(strict_types=1);

namespace Wob\Shared\Infrastructure;

use DateTimeImmutable;
use Wob\Shared\Domain\Clock;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable("now");
    }
}
