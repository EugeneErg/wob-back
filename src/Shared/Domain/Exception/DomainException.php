<?php

declare(strict_types=1);

namespace Wob\Shared\Domain\Exception;

use RuntimeException;

/**
 * A rule of the domain was broken. Distinct from an infrastructure failure:
 * this one is the caller fault and maps to 4xx, never to 500.
 */
abstract class DomainException extends RuntimeException
{
}
