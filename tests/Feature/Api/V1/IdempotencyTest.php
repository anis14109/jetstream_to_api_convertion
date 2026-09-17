<?php

namespace Tests\Feature\Api\V1;

use App\Models\IdempotencyKey;
use App\Models\User;
use App\Services\Api\V1\ChangeTracker;
use App\Services\Api\V1\IdempotencyService;
use App\Sync\ConflictPolicy;
use App\Sync\SyncEngine;
use App\Sync\SyncResourceRegistry;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\FlakySyncResourceHandler;
use Tests\TestCase;

/**
 * Database-level guarantees behind idempotency.
 *
 * These tests exercise the exact mechanism that makes concurrency safe: the
 * unique (user_id, key_hash) constraint plus the reservation state machine.
 * Together they guarantee that a second request holding the same operation id
 * can never execute the business operation.
 *
 * The genuinely concurrent case (parallel OS processes sharing one database) is
 * covered separately by Tests\Feature\Sync\ConcurrencyTest, because the
 * in-memory SQLite database used here cannot be shared across processes.
 */
class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private IdempotencyService $idempotency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->idempotency = app(IdempotencyService::class);
    }

    private function operation(string $id, string $name = 'Bob'): array
    {
        return [
            'operation_id' => 'op-1',
            'entity_type' => 'student',
            'entity_id' => $id,
            'operation' => 'create',
            'data' => ['name' => $name],
        ];
    }

    public function test_a_completed_reservation_replays(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::ulid();
        $operation = $this->operation($id);
        $fingerprint = $this->idempotency->fingerprint($operation);

        $first = $this->idempotency->begin($user, 'op-1', $fingerprint, 'student', $id, 'create');
        $this->assertSame('new', $first['status']);

        $this->idempotency->complete($first['key'], 200, ['ok' => true]);

        $second = $this->idempotency->begin($user, 'op-1', $fingerprint, 'student', $id, 'create');
        $this->assertSame('replay', $second['status']);
        $this->assertSame(['ok' => true], $second['response']);
    }

    public function test_an_in_flight_reservation_is_reported_as_pending(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::ulid();
        $fingerprint = $this->idempotency->fingerprint($this->operation($id));

        $this->idempotency->begin($user, 'op-1', $fingerprint, 'student', $id, 'create');

        $second = $this->idempotency->begin($user, 'op-1', $fingerprint, 'student', $id, 'create');
        $this->assertSame('pending', $second['status']);
    }

    public function test_the_same_operation_id_with_a_different_payload_conflicts(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::ulid();

        $first = $this->idempotency->fingerprint($this->operation($id, 'Bob'));
        $second = $this->idempotency->fingerprint($this->operation($id, 'Someone Else'));

        $this->idempotency->begin($user, 'op-1', $first, 'student', $id, 'create');

        $result = $this->idempotency->begin($user, 'op-1', $second, 'student', $id, 'create');
        $this->assertSame('conflict', $result['status']);
    }

    public function test_the_unique_index_rejects_a_duplicate_reservation(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::ulid();

        $attributes = [
            'user_id' => $user->id,
            'key_hash' => $this->idempotency->hashOperation($user, 'op-1'),
            'request_hash' => 'fingerprint',
            'entity_type' => 'student',
            'entity_id' => $id,
            'operation' => 'create',
            'response_code' => 200,
        ];

        IdempotencyKey::create($attributes);

        $this->expectException(QueryException::class);

        IdempotencyKey::create($attributes);
    }

    public function test_releasing_a_reservation_allows_a_retry(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::ulid();
        $fingerprint = $this->idempotency->fingerprint($this->operation($id));

        $first = $this->idempotency->begin($user, 'op-1', $fingerprint, 'student', $id, 'create');
        $this->idempotency->release($first['key']);

        $this->assertNull($this->idempotency->find($user, 'op-1'));

        $retry = $this->idempotency->begin($user, 'op-1', $fingerprint, 'student', $id, 'create');
        $this->assertSame('new', $retry['status']);
    }

    public function test_a_pending_reservation_prevents_the_engine_from_executing_the_operation(): void
    {
        $user = User::factory()->create();
        $id = (string) Str::ulid();
        $operation = $this->operation($id);

        // Simulate a first request that has reserved the id and is still busy.
        $this->idempotency->begin(
            $user,
            'op-1',
            $this->idempotency->fingerprint($operation),
            'student',
            $id,
            'create',
        );

        $result = app(SyncEngine::class)->push($user, 'device-1', [$operation]);

        $this->assertCount(1, $result['pending']);
        $this->assertCount(0, $result['applied']);
        $this->assertDatabaseCount('students', 0);
    }

    public function test_a_failed_operation_leaves_no_reservation_and_can_be_retried(): void
    {
        $registry = app(SyncResourceRegistry::class);
        $registry->register(new FlakySyncResourceHandler('attendance', ConflictPolicy::ManualResolution, app(ChangeTracker::class)));

        $user = User::factory()->create();
        $id = (string) Str::ulid();
        $operation = [
            'operation_id' => 'op-flaky-1',
            'entity_type' => 'attendance',
            'entity_id' => $id,
            'operation' => 'create',
            'data' => ['name' => 'Roll call'],
        ];

        // First attempt fails at the DB layer and rolls the whole transaction
        // back, including the reservation.
        try {
            app(SyncEngine::class)->push($user, 'device-1', [$operation]);
            $this->fail('The first push should have thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated business failure.', $exception->getMessage());
        }

        $this->assertNull($this->idempotency->find($user, 'op-flaky-1'));
        $this->assertDatabaseCount('change_logs', 0);

        // Retry with the same operation id now succeeds and creates one row.
        $result = app(SyncEngine::class)->push($user, 'device-1', [$operation]);
        $this->assertCount(1, $result['applied']);
        $this->assertDatabaseCount('change_logs', 1);

        // A subsequent replay does not execute again.
        $replay = app(SyncEngine::class)->push($user, 'device-1', [$operation]);
        $this->assertCount(1, $replay['replayed']);
        $this->assertDatabaseCount('change_logs', 1);
    }

    public function test_the_fingerprint_is_order_independent(): void
    {
        $a = $this->idempotency->fingerprint(['b' => 2, 'a' => 1, 'nested' => ['y' => 1, 'x' => 2]]);
        $b = $this->idempotency->fingerprint(['nested' => ['x' => 2, 'y' => 1], 'a' => 1, 'b' => 2]);

        $this->assertSame($a, $b);
    }
}
