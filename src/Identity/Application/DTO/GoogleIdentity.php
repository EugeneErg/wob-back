<?php

declare(strict_types=1);

namespace Wob\Identity\Application\DTO;

/** The claims that survived verification. Nothing here is trusted until it has. */
final readonly class GoogleIdentity
{
    public function __construct(
        public string $subject,
        public string $email,
        public bool $emailVerified,
        public string $name,
        public ?string $picture,
    ) {
    }
}
