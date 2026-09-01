<?php

declare(strict_types=1);

namespace Wob\Achievements\Infrastructure\Persistence\Database;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Ramsey\Uuid\Uuid;
use Wob\Achievements\Domain\Model\Award;
use Wob\Achievements\Domain\Repository\AwardRepository;

final readonly class DatabaseAwardRepository implements AwardRepository
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function grant(Award $award): bool
    {
        if ($this->has($award->userId, $award->code, $award->subjectId)) {
            return false;
        }

        // insertOrIgnore rather than insert: two requests can reach the same
        // milestone at once — a level finished on two devices, a board rechecked
        // twice — and the unique key is the real guard. Losing that race should
        // be a no-op, not a 500 in the middle of somebody's victory screen.
        $written = $this->db->table('awards')->insertOrIgnore([
            'id' => Uuid::uuid4()->toString(),
            'user_id' => $award->userId,
            'code' => $award->code,
            'subject_type' => $award->subjectType,
            'subject_id' => $award->subjectId,
            'points' => $award->points,
            'awarded_at' => $award->awardedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $written > 0;
    }

    public function has(string $userId, string $code, ?string $subjectId = null): bool
    {
        return $this->db->table('awards')
            ->where('user_id', $userId)
            ->where('code', $code)
            ->when(
                $subjectId === null,
                static fn ($q) => $q->whereNull('subject_id'),
                static fn ($q) => $q->where('subject_id', $subjectId),
            )
            ->exists();
    }

    public function forUser(string $userId): array
    {
        return $this->db->table('awards')
            ->where('user_id', $userId)
            ->orderByDesc('awarded_at')
            ->get()
            ->map(static fn (object $r): Award => new Award(
                $r->user_id,
                $r->code,
                $r->subject_type,
                $r->subject_id,
                (int) $r->points,
                new DateTimeImmutable($r->awarded_at),
            ))
            ->all();
    }

    public function totalPoints(string $userId): int
    {
        return (int) $this->db->table('awards')->where('user_id', $userId)->sum('points');
    }

    public function ranking(int $limit = 50): array
    {
        $rows = $this->db->table('awards')
            ->join('users', 'users.id', '=', 'awards.user_id')
            ->groupBy('users.id', 'users.display_name', 'users.avatar_url')
            ->orderByDesc('points')
            ->orderBy('users.display_name')
            ->limit($limit)
            ->selectRaw('users.id, users.display_name, users.avatar_url, SUM(awards.points) as points, COUNT(*) as awards')
            ->get();

        $place = 0;

        return $rows->values()->map(static function (object $r) use (&$place): array {
            return [
                'place' => ++$place,
                'userId' => $r->id,
                'name' => $r->display_name,
                'avatar' => $r->avatar_url,
                'points' => (int) $r->points,
                'awards' => (int) $r->awards,
            ];
        })->all();
    }
}
