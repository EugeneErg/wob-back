<?php

declare(strict_types=1);

namespace Wob\Publishing\Presentation\Console;

use Illuminate\Console\Command;
use Wob\Publishing\Application\Job\VerifyPendingRuns;

final class VerifyRuns extends Command
{
    protected $signature = 'wob:verify-runs {--batch=50}';

    protected $description = 'Replay unverified speedrun records and settle them';

    public function handle(VerifyPendingRuns $job): int
    {
        $tally = $job((int) $this->option('batch'));

        $this->info(sprintf(
            'checked %d — %d genuine, %d rejected, %d could not be checked',
            $tally['checked'],
            $tally['genuine'],
            $tally['rejected'],
            $tally['unavailable'],
        ));

        // A batch nobody could check is not a success. Exiting non-zero lets a
        // cron wrapper notice the verifier has been unreachable, rather than
        // reporting quiet progress for days while nothing is being checked.
        return $tally['unavailable'] > 0 && $tally['genuine'] === 0 ? self::FAILURE : self::SUCCESS;
    }
}
