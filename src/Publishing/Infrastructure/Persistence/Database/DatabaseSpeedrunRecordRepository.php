<?php

declare(strict_types=1);

namespace Wob\Publishing\Infrastructure\Persistence\Database;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Wob\Publishing\Domain\Model\SpeedrunRecord;
use Wob\Publishing\Domain\Repository\SpeedrunRecordRepository;
use Wob\Publishing\Domain\ValueObject\ReleaseId;

final readonly class DatabaseSpeedrunRecordRepository implements SpeedrunRecordRepository
{
    public function __construct(private ConnectionInterface $db)
    {
    }

    public function save(SpeedrunRecord $record): void
    {
        $this->db->table('speedrun_records')->insert([
            'id' => $record->id,
            'release_id' => $record->releaseId->value,
            'runner_id' => $record->runnerId,
            'scope' => $record->scope,
            'target_public_id' => $record->targetPublicId,
            'category' => $record->category,
            'ticks' => $record->ticks,
            'input' => json_encode($record->input, JSON_THROW_ON_ERROR),
            'seed' => $record->seed,
            'rules_version' => $record->rulesVersion,
            'verified_at' => $record->verifiedAt(),
            'created_at' => $record->setAt,
            'updated_at' => now(),
        ]);
    }

    public function find(string $id): ?SpeedrunRecord
    {
        $row = $this->db->table('speedrun_records')->where('id', $id)->first();

        return $row === null ? null : $this->hydrate($row);
    }

    public function leaderboard(
        ReleaseId $releaseId,
        string $scope,
        ?string $targetPublicId,
        string $category,
        int $limit = 50,
        ?string $rulesVersion = null,
        ?string $viewerId = null,
    ): array {
        // The fastest run per runner, then those ranked against each other.
        // Postgres does this in one pass with DISTINCT ON, which is the whole
        // reason to reach for it here: the alternative is fetching every
        // attempt anyone ever made and thinning it out in PHP.
        $best = $this->db->table('speedrun_records')
            ->select($this->db->raw('DISTINCT ON (runner_id) runner_id, id, ticks, created_at, verified_at'))
            ->where('release_id', $releaseId->value)
            ->where('scope', $scope)
            ->where('category', $category)
            ->when(
                $targetPublicId === null,
                static fn ($q) => $q->whereNull('target_public_id'),
                static fn ($q) => $q->where('target_public_id', $targetPublicId),
            )
            // A release pins the content; the rules version pins the physics.
            // Both are needed to say "the same game": a solver change alters
            // what is achievable, so times from two versions of the physics are
            // no more comparable than times on two different levels.
            ->when(
                $rulesVersion !== null,
                static fn ($q) => $q->where('rules_version', $rulesVersion),
            )
            // An unchecked time is a claim, and a claim does not get to hold
            // first place in public. Verification runs out of band — it takes
            // as long as playing the run did — so between submitting and being
            // checked there is a window, and a fabricated time sitting at the
            // top of a board people watch is not something to leave open for
            // the length of a cron interval.
            //
            // Runners still see their own unverified times, because from their
            // side the run happened and silence would read as the game having
            // lost it.
            ->where(static function ($q) use ($viewerId): void {
                $q->whereNotNull('verified_at');

                if ($viewerId !== null) {
                    $q->orWhere('runner_id', $viewerId);
                }
            })
            ->orderBy('runner_id')
            ->orderBy('ticks')
            ->orderBy('created_at');

        // fromSub rather than a raw FROM: the subquery's bindings travel with
        // it, and stitching SQL by hand is how a leaderboard filter turns into
        // an injection point.
        $rows = $this->db->table('speedrun_records')
            ->fromSub($best, 'best')
            ->join('users', 'users.id', '=', 'best.runner_id')
            ->orderBy('best.ticks')
            ->orderBy('best.created_at')
            ->limit($limit)
            ->select([
                'best.id',
                'best.ticks',
                'best.created_at',
                'best.verified_at',
                'users.display_name',
                'users.avatar_url',
                'users.id as runner_id',
            ])
            ->get();

        // values() first, so the places come out 1, 2, 3 rather than following
        // whatever keys the collection happens to carry. Counting with a
        // captured variable was the first attempt and it gave everybody first
        // place: an arrow function captures by value, so the counter reset on
        // every row.
        return $rows->values()->map(static fn (object $r, int $i): array => [
            'place' => $i + 1,
            'recordId' => $r->id,
            'runnerId' => $r->runner_id,
            'runner' => $r->display_name,
            'avatar' => $r->avatar_url,
            'ticks' => (int) $r->ticks,
            'setAt' => $r->created_at,
            // Shown, not hidden. Until the replay worker exists every time is a
            // claim, and a board that presents claims as verified facts is
            // lying by omission.
            'verified' => $r->verified_at !== null,
        ])->all();
    }

    public function hasRunOf(ReleaseId $releaseId, string $levelPublicId, string $runnerId): bool
    {
        return $this->db->table('speedrun_records')
            ->where('release_id', $releaseId->value)
            ->where('scope', SpeedrunRecord::SCOPE_LEVEL)
            ->where('target_public_id', $levelPublicId)
            ->where('runner_id', $runnerId)
            ->exists();
    }

    public function personalBest(
        ReleaseId $releaseId,
        string $scope,
        ?string $targetPublicId,
        string $category,
        string $runnerId,
    ): ?SpeedrunRecord {
        $row = $this->db->table('speedrun_records')
            ->where('release_id', $releaseId->value)
            ->where('scope', $scope)
            ->where('category', $category)
            ->where('runner_id', $runnerId)
            ->when(
                $targetPublicId === null,
                static fn ($q) => $q->whereNull('target_public_id'),
                static fn ($q) => $q->where('target_public_id', $targetPublicId),
            )
            ->orderBy('ticks')
            ->first();

        return $row === null ? null : $this->hydrate($row);
    }

    private function hydrate(object $row): SpeedrunRecord
    {
        return new SpeedrunRecord(
            $row->id,
            new ReleaseId($row->release_id),
            $row->runner_id,
            $row->scope,
            $row->target_public_id,
            $row->category,
            (int) $row->ticks,
            json_decode($row->input, true, 512, JSON_THROW_ON_ERROR),
            (int) $row->seed,
            $row->rules_version,
            new DateTimeImmutable($row->created_at),
            // Named: twelve positional arguments is a row of values nobody can
            // check by eye, and this is the one that is optional and therefore
            // the one a future insertion would silently displace.
            verifiedAt: $row->verified_at === null ? null : new DateTimeImmutable($row->verified_at),
        );
    }
}
