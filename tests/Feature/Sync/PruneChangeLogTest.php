<?php

namespace Tests\Feature\Sync;

use App\Models\ChangeLog;
use App\Models\SyncCursor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneChangeLogTest extends TestCase
{
    use RefreshDatabase;

    private function log(User $user, \DateTimeInterface $createdAt, string $entityId = 'e-1'): ChangeLog
    {
        return ChangeLog::create([
            'user_id' => $user->id,
            'entity_type' => 'student',
            'entity_id' => $entityId,
            'operation' => 'created',
            'data' => ['id' => $entityId],
            'created_at' => $createdAt,
        ]);
    }

    private function cursor(User $user, int $acknowledged, string $client = 'device-1'): SyncCursor
    {
        return SyncCursor::create([
            'user_id' => $user->id,
            'client_id' => $client,
            'last_pulled_cursor' => $acknowledged,
            'acknowledged_cursor' => $acknowledged,
            'updated_at' => now(),
        ]);
    }

    public function test_it_prunes_acknowledged_entries_older_than_the_retention_window(): void
    {
        config(['api.sync.change_log_retention_days' => 30]);

        $user = User::factory()->create();
        $old = $this->log($user, now()->subDays(40));
        $recent = $this->log($user, now()->subDays(1));
        $this->cursor($user, 2);

        $this->artisan('sync:prune-change-log')->assertSuccessful();

        $this->assertDatabaseMissing('change_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('change_logs', ['id' => $recent->id]);
    }

    public function test_it_keeps_entries_beyond_the_acknowledged_cursor(): void
    {
        config(['api.sync.change_log_retention_days' => 30]);

        $user = User::factory()->create();
        $first = $this->log($user, now()->subDays(40));
        $second = $this->log($user, now()->subDays(40), 'e-2');
        $this->cursor($user, 1);

        $this->artisan('sync:prune-change-log')->assertSuccessful();

        $this->assertDatabaseMissing('change_logs', ['id' => $first->id]);
        $this->assertDatabaseHas('change_logs', ['id' => $second->id]);
    }

    public function test_it_keeps_entries_for_users_without_a_cursor(): void
    {
        config(['api.sync.change_log_retention_days' => 30]);

        $user = User::factory()->create();
        $log = $this->log($user, now()->subDays(40));

        $this->artisan('sync:prune-change-log')->assertSuccessful();

        $this->assertDatabaseHas('change_logs', ['id' => $log->id]);
    }

    public function test_it_uses_the_slowest_client_acknowledgement(): void
    {
        config(['api.sync.change_log_retention_days' => 30]);

        $user = User::factory()->create();
        $first = $this->log($user, now()->subDays(40));
        $second = $this->log($user, now()->subDays(40), 'e-2');
        $this->cursor($user, 2, 'fast-device');
        $this->cursor($user, 1, 'slow-device');

        $this->artisan('sync:prune-change-log')->assertSuccessful();

        $this->assertDatabaseMissing('change_logs', ['id' => $first->id]);
        $this->assertDatabaseHas('change_logs', ['id' => $second->id]);
    }

    public function test_dry_run_reports_without_deleting(): void
    {
        config(['api.sync.change_log_retention_days' => 30]);

        $user = User::factory()->create();
        $log = $this->log($user, now()->subDays(40));
        $this->cursor($user, 1);

        $this->artisan('sync:prune-change-log', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('change_logs', ['id' => $log->id]);
    }

    public function test_it_does_nothing_when_retention_is_disabled(): void
    {
        config(['api.sync.change_log_retention_days' => 0]);

        $user = User::factory()->create();
        $log = $this->log($user, now()->subDays(40));
        $this->cursor($user, 1);

        $this->artisan('sync:prune-change-log')->assertSuccessful();

        $this->assertDatabaseHas('change_logs', ['id' => $log->id]);
    }
}
