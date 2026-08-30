<?php

declare(strict_types=1);

namespace Wob\Identity\Infrastructure\Persistence\Database;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Wob\Identity\Domain\Model\EmailAddress;
use Wob\Identity\Domain\Model\GoogleSubject;
use Wob\Identity\Domain\Model\User;
use Wob\Identity\Domain\Model\UserId;
use Wob\Identity\Domain\Repository\UserRepository;
use Wob\Shared\Domain\DomainEventBus;

final readonly class DatabaseUserRepository implements UserRepository
{
    public function __construct(
        private ConnectionInterface $db,
        private DomainEventBus $events,
    ) {
    }

    public function findByGoogleSubject(GoogleSubject $subject): ?User
    {
        return $this->hydrate($this->db->table("users")->where("google_sub", $subject->value)->first());
    }

    public function find(UserId $id): ?User
    {
        return $this->hydrate($this->db->table("users")->where("id", $id->value)->first());
    }

    public function save(User $user): void
    {
        $values = [
            "google_sub" => $user->googleSubject->value,
            "email" => $user->email()->value,
            "display_name" => $user->displayName(),
            "avatar_url" => $user->avatarUrl(),
            "last_seen_at" => $user->lastSeenAt(),
            "updated_at" => now(),
        ];

        // upsert rather than exists-then-insert: two sign-ins racing on a first
        // visit would otherwise both see "no user" and both try to insert.
        $this->db->table("users")->upsert(
            [["id" => $user->id->value, ...$values, "created_at" => now()]],
            ["google_sub"],
            array_keys($values),
        );

        // Events are published only once the write succeeded. Publishing before
        // would announce a registration that a failed transaction never made.
        $this->events->publishAll($user->releaseEvents());
    }

    private function hydrate(?object $row): ?User
    {
        if ($row === null) {
            return null;
        }

        return User::reconstitute(
            new UserId($row->id),
            new GoogleSubject($row->google_sub),
            new EmailAddress($row->email),
            $row->display_name,
            $row->avatar_url,
            $row->last_seen_at === null ? null : new DateTimeImmutable($row->last_seen_at),
        );
    }
}
