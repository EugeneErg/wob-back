<?php

declare(strict_types=1);

namespace Wob\Identity\Application\Handler;

use Wob\Identity\Application\Command\SignInWithGoogle;
use Wob\Identity\Application\Exception\AuthenticationFailed;
use Wob\Identity\Application\Port\GoogleIdentityVerifier;
use Wob\Identity\Domain\Model\EmailAddress;
use Wob\Identity\Domain\Model\GoogleSubject;
use Wob\Identity\Domain\Model\User;
use Wob\Identity\Domain\Repository\UserRepository;
use Wob\Shared\Domain\Clock;

/**
 * Sign in, registering on the way if this is a first visit.
 *
 * There is deliberately no separate "register" command. A Google sign-in cannot
 * distinguish the two from the outside, and asking the client to say which it
 * meant would only give it a way to be wrong.
 */
final readonly class SignInWithGoogleHandler
{
    public function __construct(
        private GoogleIdentityVerifier $verifier,
        private UserRepository $users,
        private Clock $clock,
    ) {
    }

    public function __invoke(SignInWithGoogle $command): User
    {
        $identity = $this->verifier->verify($command->credential);

        // An unverified address could belong to anyone. Google normally only
        // issues verified ones, but "normally" is not a security boundary.
        if (!$identity->emailVerified) {
            throw AuthenticationFailed::because("This Google account has no verified email address");
        }

        $subject = new GoogleSubject($identity->subject);
        $email = new EmailAddress($identity->email);
        $now = $this->clock->now();
        $user = $this->users->findByGoogleSubject($subject);

        if ($user === null) {
            $user = User::register($subject, $email, $identity->name, $identity->picture, $now);
        } else {
            $user->signedIn($email, $identity->name, $identity->picture, $now);
        }

        $this->users->save($user);

        return $user;
    }
}
