<?php

declare(strict_types=1);

namespace Wob\Publishing\Application\Job;

use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Wob\Publishing\Domain\Repository\ReleaseRepository;
use Wob\Publishing\Domain\Repository\SpeedrunRecordRepository;
use Wob\Publishing\Domain\Service\RunVerifier;
use Wob\Publishing\Domain\ValueObject\ReleaseId;
use Wob\Shared\Domain\Clock;

/**
 * Re-runs the records nobody has checked yet.
 *
 * Out of band rather than during submission, and the reason is the player: a
 * level ends with a panel that should appear at once, and replaying a run takes
 * as long as playing it did. Verification that blocked the response would make
 * finishing a hard level feel like the game had frozen.
 *
 * Rejected runs are deleted rather than flagged. A leaderboard is a claim about
 * who was fastest, and keeping a proven-false time on it under a small label
 * asks every reader to do the filtering the system already did.
 */
final readonly class VerifyPendingRuns
{
    public function __construct(
        private SpeedrunRecordRepository $records,
        private ReleaseRepository $releases,
        private RunVerifier $verifier,
        private ConnectionInterface $db,
        private Clock $clock,
        private ?LoggerInterface $log = null,
    ) {
    }

    /** @return array{checked: int, genuine: int, rejected: int, unavailable: int} */
    public function __invoke(int $batch = 50): array
    {
        $pending = $this->db->table('speedrun_records')
            ->whereNull('verified_at')
            ->orderBy('created_at')
            ->limit($batch)
            ->pluck('id');

        $tally = ['checked' => 0, 'genuine' => 0, 'rejected' => 0, 'unavailable' => 0];

        foreach ($pending as $id) {
            $record = $this->records->find((string) $id);

            if ($record === null) {
                continue;
            }

            $release = $this->releases->find($record->releaseId);

            if ($release === null) {
                continue;
            }

            $result = $this->verifier->verify($record, $release->content);
            $tally['checked']++;

            // Undecided means the checker could not answer — it was down, or it
            // replied with something unreadable. The record is left exactly as
            // it was, to be picked up on the next pass. Treating this as a
            // rejection would throw away honest times whenever a service
            // restarted; treating it as a pass would let a cheat through by
            // taking the checker offline.
            if (!$result->decided) {
                $tally['unavailable']++;
                $this->log?->warning('Could not verify a run', ['record' => $id, 'why' => $result->reason]);

                continue;
            }

            if ($result->genuine) {
                $this->db->table('speedrun_records')
                    ->where('id', $id)
                    ->update(['verified_at' => $this->clock->now(), 'updated_at' => now()]);

                $tally['genuine']++;

                continue;
            }

            $this->log?->info('Run rejected on replay', [
                'record' => $id,
                'reason' => $result->reason,
                'claimed' => $record->ticks,
                'actual' => $result->actualTicks,
            ]);

            $this->db->table('speedrun_records')->where('id', $id)->delete();
            $tally['rejected']++;
        }

        return $tally;
    }
}
