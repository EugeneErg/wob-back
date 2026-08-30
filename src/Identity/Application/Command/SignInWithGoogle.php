<?php

declare(strict_types=1);

namespace Wob\Identity\Application\Command;

final readonly class SignInWithGoogle
{
    public function __construct(public string $credential)
    {
    }
}
