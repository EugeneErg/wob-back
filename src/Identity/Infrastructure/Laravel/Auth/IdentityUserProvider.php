<?php

declare(strict_types=1);

namespace Wob\Identity\Infrastructure\Laravel\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Wob\Identity\Domain\Model\UserId;
use Wob\Identity\Domain\Repository\UserRepository;
use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * Resolves the id in the session back to something the guard accepts.
 *
 * Every credential method throws rather than returning false. There are no
 * credentials here — sign-in happens against Google and nowhere else — and a
 * method that quietly answers "no" would leave a password login looking merely
 * broken rather than absent. If one of these is ever called, the wiring is
 * wrong and should say so loudly.
 */
final readonly class IdentityUserProvider implements UserProvider
{
    public function __construct(private UserRepository $users)
    {
    }

    public function retrieveById($identifier): ?Authenticatable
    {
        try {
            $id = new UserId((string) $identifier);
        } catch (InvariantViolation) {
            // A malformed id in a cookie is a forged or stale session, not a
            // server error.
            return null;
        }

        return $this->users->find($id) === null ? null : new SignedInUser($id->value);
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, $token): void
    {
    }

    /** @param array<string, mixed> $credentials */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        throw new \LogicException('Sign-in goes through Google, not credentials');
    }

    /** @param array<string, mixed> $credentials */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        throw new \LogicException('Sign-in goes through Google, not credentials');
    }

    /** @param array<string, mixed> $credentials */
    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void
    {
    }
}
