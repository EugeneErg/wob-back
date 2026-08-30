<?php

declare(strict_types=1);

namespace Wob\Identity\Infrastructure\Laravel\Auth;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The thinnest thing Laravel auth will accept: an id, and nothing else.
 *
 * The obvious shortcut is to make the domain User an Eloquent model so the
 * framework can hand it back directly. That is exactly how the domain ends up
 * depending on the framework — User would inherit save(), a connection and a
 * lifecycle, and the invariants would stop being the only way to change it.
 *
 * So the framework gets this, which carries an id and admits to nothing else,
 * and ResolveDomainUser turns that id into the real User once per request.
 * There is no remember token because there is no "remember me": the session is
 * the whole story, and a long-lived token to re-mint it is a second credential
 * to keep safe for no gain.
 */
final readonly class SignedInUser implements Authenticatable
{
    public function __construct(private string $id)
    {
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): string
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }
}
