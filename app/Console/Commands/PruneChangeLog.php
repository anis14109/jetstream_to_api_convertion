<?php

namespace App\Console\Commands;

use App\Models\ChangeLog;
use App\Models\IdempotencyKey;
use App\Models\SyncCursor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('sync:prune-change-log
    {--days= : Override the configured change-log retention window in days}
    {--idempotency-days= : Override the configured idempotency-key retention window in days}
    {--dry-run : Report how many entries would be pruned without deleting them}')]
#[Description('Prune acknowledged change-log entries and stale idempotency keys')]
class PruneChangeLog extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $changeLogDays = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('api.sync.change_log_retention_days');

        $idempotencyDays = $this->option('idempotency-days') !== null
            ? (int) $this->option('idempotency-days')
            : (int) config('api.sync.idempotency_retention_days');

        if ($changeLogDays <= 0 && $idempotencyDays <= 0) {
            $this->components->info('Pruning is disabled (both retention windows are 0).');

            return self::SUCCESS;
        }

        if ($changeLogDays > 0) {
            $this->pruneChangeLog($changeLogDays, $dryRun);
        }

        if ($idempotencyDays > 0) {
            $this->pruneIdempotencyKeys($idempotencyDays, $dryRun);
        }

        return self::SUCCESS;
    }

    /**
     * Remove journal entries older than the retention window that every client
     * of a user has already acknowledged. The boundary is the slowest client's
     * acknowledged cursor, so an active client can never lose data it still
     * needs. Users without any cursor (no registered client) are left untouched.
     */
    private function pruneChangeLog(int $retentionDays, bool $dryRun): void
    {
        $cutoff = now()->subDays($retentionDays);

        $pruned = 0;
        $users = 0;

        // Grouped aggregates cannot use chunk()/chunkById() because those order
        // by a primary key that is not selected; stream with cursor() instead.
        SyncCursor::query()
            ->select('user_id', DB::raw('MIN(acknowledged_cursor) as min_acknowledged_cursor'))
            ->groupBy('user_id')
            ->orderBy('user_id')
            ->cursor()
            ->each(function (SyncCursor $cursor) use ($cutoff, $dryRun, &$pruned, &$users): void {
                $minAcknowledged = (int) $cursor->min_acknowledged_cursor;

                // A client has not acknowledged anything yet: keep its history.
                if ($minAcknowledged <= 0) {
                    return;
                }

                $query = ChangeLog::query()
                    ->where('user_id', $cursor->user_id)
                    ->where('id', '<=', $minAcknowledged)
                    ->where('created_at', '<', $cutoff);

                $users++;
                $pruned += $dryRun ? $query->count() : $query->delete();
            });

        $verb = $dryRun ? 'Would prune' : 'Pruned';

        $this->components->info(sprintf(
            '%s %d change-log %s across %d user(s) older than %s.',
            $verb,
            $pruned,
            $pruned === 1 ? 'entry' : 'entries',
            $users,
            $cutoff->toDateTimeString(),
        ));
    }

    /**
     * Remove completed idempotency records older than the retention window.
     * Pending reservations are never removed. Retention must comfortably exceed
     * the longest client retry window, otherwise a very late retry could be
     * treated as a new operation and execute twice.
     */
    private function pruneIdempotencyKeys(int $retentionDays, bool $dryRun): void
    {
        $cutoff = now()->subDays($retentionDays);

        $query = IdempotencyKey::query()
            ->whereNotNull('completed_at')
            ->where('completed_at', '<', $cutoff);

        $count = $dryRun ? $query->count() : $query->delete();

        $verb = $dryRun ? 'Would prune' : 'Pruned';

        $this->components->info(sprintf(
            '%s %d completed idempotency %s older than %s.',
            $verb,
            $count,
            $count === 1 ? 'key' : 'keys',
            $cutoff->toDateTimeString(),
        ));
    }
}
