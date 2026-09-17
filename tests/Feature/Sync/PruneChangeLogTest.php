<?php

namespace Tests\Feature\Sync;

use App\Models\ChangeLog;
use App\Models\IdempotencyKey;
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

    private function idempotencyKey(User $user, ?\DateTimeInterface $completedAt, string $operationId = 'op-1'): IdempotencyKey
    {
        return IdempotencyKey::create([
            'user_id' => $user->id,
            'key_hash' => hash('sha256', $user->id.'|'.$operationId),
            'request_hash' => 'fingerprint',
            'entity_type' => 'student',
            'entity_id' => 'e-1',
            'operation' => 'create',
            'response_code' => 200,
            'response_json' => ['ok' => true],
            'completed_at' => $completedAt,
            'created_at' => now()->subDays(90),
        ]);
    }

    public function test_it_prunes_completed_idempotency_keys_older_than_the_retention_window(): void
    {
        config(['api.sync.idempotency_retention_days' => 30]);

        $user = User::factory()->create();
        $old = $this->idempotencyKey($user, now()->subDays(40), 'old');
        $recent = $this->idempotencyKey($user, now()->subDays(1), 'recent');

        $this->artisan('sync:prune-change-log')->assertSuccessful();

        $this->assertDatabaseMissing('idempotency_keys', ['id' => $old->id]);
        $this->assertDatabaseHas('idempotency_keys', ['id' => $recent->id]);
    }

    public function test_it_never_prunes_pending_idempotency_reservations(): void
    {
        config(['api.sync.idempotency_retention_days' => 30]);

        $user = User::factory()->create();
        $pending = $this->idempotencyKey($user, null, 'pending');

        $this->artisan('sync:prune-change-log')->assertSuccessful();

        $this->assertDatabaseHas('idempotency_keys', ['id' => $pending->id]);
    }

    public function test_idempotency_pruning_honours_the_dry_run_flag(): void
    {
        config(['api.sync.idempotency_retention_days' => 30]);

        $user = User::factory()->create();
        $old = $this->idempotencyKey($user, now()->subDays(40), 'old');

        $this->artisan('sync:prune-change-log', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('idempotency_keys', ['id' => $old->id]);
    }

    public function test_the_idempotency_retention_window_can_be_overridden(): void
    {
        config([
            'api.sync.change_log_retention_days' => 0,
            'api.sync.idempotency_retention_days' => 0,
        ]);

        $user = User::factory()->create();
        $old = $this->idempotencyKey($user, now()->subDays(40), 'old');

        $this->artisan('sync:prune-change-log', ['--idempotency-days' => 1])->assertSuccessful();

        $this->assertDatabaseMissing('idempotency_keys', ['id' => $old->id]);
    }

    public function test_the_change_log_retention_window_can_be_overridden(): void
    {
        config(['api.sync.change_log_retention_days' => 0]);

        $user = User::factory()->create();
        $log = $this->log($user, now()->subDays(40));
        $this->cursor($user, 1);

        $this->artisan('sync:prune-change-log', ['--days' => 1])->assertSuccessful();

        $this->assertDatabaseMissing('change_logs', ['id' => $log->id]);
    }

    public function test_pruning_one_user_does_not_touch_another_users_history(): void
    {
        config(['api.sync.change_log_retention_days' => 30]);

        $pruned = User::factory()->create();
        $other = User::factory()->create();

        $prunedLog = $this->log($pruned, now()->subDays(40));
        $otherLog = $this->log($other, now()->subDays(40));

        $this->cursor($pruned, 1);

        $this->artisan('sync:prune-change-log')->assertSuccessful();

        $this->assertDatabaseMissing('change_logs', ['id' => $prunedLog->id]);
        $this->assertDatabaseHas('change_logs', ['id' => $otherLog->id]);
    }
}
