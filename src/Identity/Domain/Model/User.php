<?php

declare(strict_types=1);

namespace Wob\Identity\Domain\Model;

use DateTimeImmutable;
use Wob\Identity\Domain\Event\UserRegistered;
use Wob\Shared\Domain\AggregateRoot;
use Wob\Shared\Domain\Exception\InvariantViolation;

/**
 * A person who signs in.
 *
 * Profile fields are a cache of what Google last told us, not a source of truth,
 * and they are refreshed on every sign-in. That is why there is no "edit
 * profile" here: nothing in the game depends on the display name, and inventing
 * an editable copy would immediately raise the question of which one wins.
 */
final class User extends AggregateRoot
{
    private function __construct(
        public readonly UserId $id,
        public readonly GoogleSubject $googleSubject,
        private EmailAddress $email,
        private string $displayName,
        private ?string $avatarUrl,
        private ?DateTimeImmutable $lastSeenAt = null,
    ) {
    }

    public static function register(
        GoogleSubject $subject,
        EmailAddress $email,
        string $displayName,
        ?string $avatarUrl,
        DateTimeImmutable $at,
    ): self {
        $user = new self(UserId::next(), $subject, $email, self::cleanName($displayName, $email), $avatarUrl, $at);
        $user->recordThat(new UserRegistered($user->id, $at));

        return $user;
    }

    public static function reconstitute(
        UserId $id,
        GoogleSubject $subject,
        EmailAddress $email,
        string $displayName,
        ?string $avatarUrl,
        ?DateTimeImmutable $lastSeenAt,
    ): self {
        return new self($id, $subject, $email, $displayName, $avatarUrl, $lastSeenAt);
    }

    public function email(): EmailAddress
    {
        return $this->email;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function avatarUrl(): ?string
    {
        return $this->avatarUrl;
    }

    public function lastSeenAt(): ?DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    /** Google is authoritative for the profile, so a sign-in refreshes it. */
    public function signedIn(
        EmailAddress $email,
        string $displayName,
        ?string $avatarUrl,
        DateTimeImmutable $at,
    ): void {
        $this->email = $email;
        $this->displayName = self::cleanName($displayName, $email);
        $this->avatarUrl = $avatarUrl;
        $this->lastSeenAt = $at;
    }

    private static function cleanName(string $name, EmailAddress $fallback): string
    {
        $name = trim($name);

        if ($name === "") {
            $name = explode("@", $fallback->value)[0];
        }

        if (mb_strlen($name) > 200) {
            $name = mb_substr($name, 0, 200);
        }

        if ($name === "") {
            throw InvariantViolation::because("A user must have a display name");
        }

        return $name;
    }
}
