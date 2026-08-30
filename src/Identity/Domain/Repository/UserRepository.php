<?php

declare(strict_types=1);

namespace Wob\Identity\Domain\Repository;

use Wob\Identity\Domain\Model\GoogleSubject;
use Wob\Identity\Domain\Model\User;
use Wob\Identity\Domain\Model\UserId;

interface UserRepository
{
    public function findByGoogleSubject(GoogleSubject $subject): ?User;

    public function find(UserId $id): ?User;

    public function save(User $user): void;
}
