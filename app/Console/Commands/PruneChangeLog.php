<?php

namespace App\Console\Commands;

use App\Models\ChangeLog;
use App\Models\SyncCursor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('sync:prune-change-log
    {--days= : Override the configured retention window in days}
    {--dry-run : Report how many entries would be pruned without deleting them}')]
#[Description('Prune change-log entries that every client has already acknowledged')]
class PruneChangeLog extends Command
{
    public function handle(): int
    {
        $retentionDays = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('api.sync.change_log_retention_days');

        if ($retentionDays <= 0) {
            $this->components->info('Change-log pruning is disabled (retention days is 0).');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($retentionDays);
        $dryRun = (bool) $this->option('dry-run');

        $pruned = 0;
        $scanned = 0;

        SyncCursor::query()
            ->select('user_id', DB::raw('MIN(acknowledged_cursor) as min_acknowledged_cursor'))
            ->groupBy('user_id')
            ->orderBy('user_id')
            ->chunk(100, function ($cursors) use ($cutoff, $dryRun, &$pruned, &$scanned): void {
                foreach ($cursors as $cursor) {
                    $minAcknowledged = (int) $cursor->min_acknowledged_cursor;

                    if ($minAcknowledged <= 0) {
                        continue;
                    }

                    $query = ChangeLog::query()
                        ->where('user_id', $cursor->user_id)
                        ->where('id', '<=', $minAcknowledged)
                        ->where('created_at', '<', $cutoff);

                    $scanned++;

                    $count = $dryRun ? $query->count() : $query->delete();

                    $pruned += $count;
                }
            });

        $verb = $dryRun ? 'Would prune' : 'Pruned';

        $this->components->info(sprintf(
            '%s %d change-log %s across %d user(s) older than %s.',
            $verb,
            $pruned,
            $pruned === 1 ? 'entry' : 'entries',
            $scanned,
            $cutoff->toDateTimeString(),
        ));

        return self::SUCCESS;
    }
}
