<?php

declare(strict_types=1);

namespace Wob\Publishing\Infrastructure\Persistence\Database;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Ramsey\Uuid\Uuid;
use Wob\Publishing\Domain\Model\Vote;
use Wob\Publishing\Domain\Repository\VoteRepository;
use Wob\Publishing\Domain\ValueObject\Rating;
use Wob\Publishing\Domain\ValueObject\ReleaseId;

final readonly class DatabaseVoteRepository implements VoteRepository
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function forLevel(ReleaseId $releaseId, string $levelPublicId): array
    {
        $rows = $this->db->table('votes')
            ->where('release_id', $releaseId->value)
            ->where('level_public_id', $levelPublicId)
            ->get()
            ->all();

        return array_map($this->hydrate(...), $rows);
    }

    public function findOne(ReleaseId $releaseId, string $levelPublicId, string $voterId): ?Vote
    {
        $row = $this->db->table('votes')
            ->where('release_id', $releaseId->value)
            ->where('level_public_id', $levelPublicId)
            ->where('voter_id', $voterId)
            ->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function save(Vote $vote): void
    {
        $this->saveAll([$vote]);
    }

    public function saveAll(array $votes): void
    {
        if ($votes === []) {
            return;
        }

        $rows = array_map(static fn (Vote $v): array => [
            'id' => Uuid::uuid4()->toString(),
            'release_id' => $v->releaseId->value,
            'level_public_id' => $v->levelId,
            'voter_id' => $v->voterId,
            'rating' => $v->rating->value,
            'carried_over' => $v->carriedOver,
            'weight' => $v->weight,
            'created_at' => $v->castAt,
            'updated_at' => now(),
        ], $votes);

        // Changing your mind replaces your vote rather than adding a second
        // one — the unique key is what makes one player one opinion.
        $this->db->table('votes')->upsert(
            $rows,
            ['release_id', 'level_public_id', 'voter_id'],
            ['rating', 'carried_over', 'weight', 'updated_at'],
        );
    }

    /**
     * The weighted mean of every rating on this release.
     *
     * Weighted rather than plain, because an opinion formed on an earlier
     * version of a level counts for less than one formed on this one — that is
     * what weight means. A plain average would let ratings of content nobody
     * playing today has seen carry the same force as ratings of what is
     * actually there.
     */
    public function averageRating(ReleaseId $releaseId): float
    {
        $row = $this->db->table('votes')
            ->where('release_id', $releaseId->value)
            ->selectRaw('SUM(rating * weight) as total, SUM(weight) as divisor')
            ->first();

        $divisor = (float) ($row->divisor ?? 0);

        return $divisor <= 0.0 ? 0.0 : (float) $row->total / $divisor;
    }

    public function countFor(ReleaseId $releaseId): int
    {
        return $this->db->table('votes')->where('release_id', $releaseId->value)->count();
    }

    private function hydrate(object $row): Vote
    {
        return new Vote(
            new ReleaseId($row->release_id),
            $row->level_public_id,
            $row->voter_id,
            new Rating((int) $row->rating),
            new DateTimeImmutable($row->created_at),
            (bool) $row->carried_over,
            (float) $row->weight,
        );
    }
}
