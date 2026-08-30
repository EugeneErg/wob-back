<?php

declare(strict_types=1);

namespace Wob\Shared\Domain;

use DateTimeImmutable;

/**
 * Time is an input, not an ambient fact. Domain code that calls now() directly
 * cannot be tested without waiting, and cannot be replayed at all.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
