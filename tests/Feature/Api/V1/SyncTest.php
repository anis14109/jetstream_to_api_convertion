<?php

namespace Tests\Feature\Api\V1;

use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\Concerns\ApiV1Helpers;
use Tests\TestCase;

class SyncTest extends TestCase
{
    use ApiV1Helpers;
    use RefreshDatabase;

    public function test_the_cursor_starts_at_zero(): void
    {
        $data = $this->register()->json('data');

        $this->authRequest($data['access_token'], 'GET', '/api/v1/sync/cursor')
            ->assertOk()
            ->assertJsonPath('data.cursor', 0);
    }

    public function test_pull_returns_changes_after_the_cursor(): void
    {
        $data = $this->register()->json('data');
        $token = $data['access_token'];

        $this->authRequest($token, 'POST', '/api/v1/students', ['name' => 'Alice'])->assertCreated();
        $this->authRequest($token, 'POST', '/api/v1/students', ['name' => 'Bob'])->assertCreated();

        $pull = $this->authRequest($token, 'GET', '/api/v1/sync/pull');
        $pull->assertOk();
        $this->assertCount(2, $pull->json('data.changes'));
        $this->assertSame('student', $pull->json('data.changes.0.entity_type'));
        $this->assertSame('created', $pull->json('data.changes.0.operation'));
        $this->assertGreaterThan(0, $pull->json('data.next_cursor'));

        $second = $this->authRequest($token, 'GET', '/api/v1/sync/pull?cursor='.$pull->json('data.next_cursor'));
        $second->assertOk();
        $this->assertCount(0, $second->json('data.changes'));
    }

    public function test_pull_does_not_acknowledge_changes(): void
    {
        $data = $this->register()->json('data');
        $token = $data['access_token'];

        $this->authRequest($token, 'POST', '/api/v1/students', ['name' => 'Alice'])->assertCreated();

        $next = $this->authRequest($token, 'GET', '/api/v1/sync/pull')->json('data.next_cursor');
        $this->assertGreaterThan(0, $next);

        $cursor = $this->authRequest($token, 'GET', '/api/v1/sync/cursor')->json('data');

        $this->assertSame(0, $cursor['acknowledged_cursor']);
        $this->assertSame($next, $cursor['last_pulled_cursor']);
        $this->assertSame(0, $cursor['cursor']);
    }

    public function test_ack_advances_the_acknowledged_cursor(): void
    {
        $data = $this->register()->json('data');
        $token = $data['access_token'];

        $this->authRequest($token, 'POST', '/api/v1/students', ['name' => 'Alice'])->assertCreated();
        $next = $this->authRequest($token, 'GET', '/api/v1/sync/pull')->json('data.next_cursor');

        $this->authRequest($token, 'POST', '/api/v1/sync/ack', ['cursor' => $next])
            ->assertOk()
            ->assertJsonPath('data.acknowledged_cursor', $next);

        $this->authRequest($token, 'GET', '/api/v1/sync/cursor')
            ->assertOk()
            ->assertJsonPath('data.acknowledged_cursor', $next)
            ->assertJsonPath('data.cursor', $next);
    }

    public function test_ack_is_idempotent(): void
    {
        $data = $this->register()->json('data');
        $token = $data['access_token'];

        $this->authRequest($token, 'POST', '/api/v1/students', ['name' => 'Alice'])->assertCreated();
        $next = $this->authRequest($token, 'GET', '/api/v1/sync/pull')->json('data.next_cursor');

        $this->authRequest($token, 'POST', '/api/v1/sync/ack', ['cursor' => $next])->assertOk();

        $second = $this->authRequest($token, 'POST', '/api/v1/sync/ack', ['cursor' => $next]);
        $second->assertOk()->assertJsonPath('data.acknowledged_cursor', $next);

        $this->assertDatabaseHas('sync_cursors', [
            'acknowledged_cursor' => $next,
        ]);
    }

    public function test_ack_rejects_a_cursor_ahead_of_delivered_changes(): void
    {
        $data = $this->register()->json('data');
        $token = $data['access_token'];

        $this->authRequest($token, 'POST', '/api/v1/students', ['name' => 'Alice'])->assertCreated();

        $this->authRequest($token, 'POST', '/api/v1/sync/ack', ['cursor' => 9999])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors('cursor');
    }

    public function test_ack_rejects_a_negative_cursor(): void
    {
        $data = $this->register()->json('data');

        $this->authRequest($data['access_token'], 'POST', '/api/v1/sync/ack', ['cursor' => -1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('cursor');
    }

    public function test_push_applies_operations_and_replays_idempotently(): void
    {
        $data = $this->register()->json('data');
        $token = $data['access_token'];
        $id = (string) Str::ulid();

        $payload = ['operations' => [[
            'operation_id' => 'op-create-1',
            'entity_type' => 'student',
            'entity_id' => $id,
            'operation' => 'create',
            'data' => ['name' => 'Bob'],
        ]]];

        $this->authRequest($token, 'POST', '/api/v1/sync/push', $payload)
            ->assertOk()
            ->assertJsonPath('data.applied.0.operation', 'create');

        $this->assertDatabaseCount('students', 1);

        $replay = $this->authRequest($token, 'POST', '/api/v1/sync/push', $payload);
        $replay->assertOk();
        $this->assertCount(1, $replay->json('data.replayed'));
        $this->assertCount(0, $replay->json('data.applied'));

        $this->assertDatabaseCount('students', 1);
    }

    public function test_reusing_an_operation_id_with_a_different_payload_conflicts(): void
    {
        $data = $this->register()->json('data');
        $token = $data['access_token'];
        $id = (string) Str::ulid();

        $this->authRequest($token, 'POST', '/api/v1/sync/push', [
            'operations' => [[
                'operation_id' => 'op-create-1',
                'entity_type' => 'student',
                'entity_id' => $id,
                'operation' => 'create',
                'data' => ['name' => 'Bob'],
            ]],
        ])->assertOk();

        $response = $this->authRequest($token, 'POST', '/api/v1/sync/push', [
            'operations' => [[
                'operation_id' => 'op-create-1',
                'entity_type' => 'student',
                'entity_id' => $id,
                'operation' => 'create',
                'data' => ['name' => 'Someone Else'],
            ]],
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT')
            ->assertJsonPath('data.idempotency_conflicts.0.reason', 'idempotency_conflict');

        $this->assertSame(1, Student::query()->count());
        $this->assertSame('Bob', Student::query()->find($id)->name);
    }

    public function test_a_create_cannot_reuse_another_users_entity_id(): void
    {
        $owner = $this->register(['email' => 'owner@example.com'])->json('data');
        $student = $this->authRequest($owner['access_token'], 'POST', '/api/v1/students', [
            'name' => 'Owner Student',
        ])->json('data');

        $attacker = $this->register(['email' => 'attacker@example.com'])->json('data');

        $this->forgetAuthGuard();

        $response = $this->authRequest($attacker['access_token'], 'POST', '/api/v1/sync/push', [
            'operations' => [[
                'operation_id' => 'op-steal-1',
                'entity_type' => 'student',
                'entity_id' => $student['id'],
                'operation' => 'create',
                'data' => ['name' => 'Stolen'],
            ]],
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('data.conflicts.0.reason', 'already_exists');

        $this->assertArrayNotHasKey('server_data', $response->json('data.conflicts.0'));
        $this->assertSame('Owner Student', Student::query()->find($student['id'])->name);
    }

    public function test_push_reports_conflicts_instead_of_overwriting(): void
    {
        $data = $this->register()->json('data');
        $token = $data['access_token'];

        $student = $this->authRequest($token, 'POST', '/api/v1/students', ['name' => 'Alice'])->json('data');

        $response = $this->authRequest($token, 'POST', '/api/v1/sync/push', [
            'operations' => [[
                'operation_id' => 'op-update-1',
                'entity_type' => 'student',
                'entity_id' => $student['id'],
                'operation' => 'update',
                'version' => 99,
                'data' => ['name' => 'Stale'],
            ]],
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'SYNC_CONFLICT')
            ->assertJsonPath('data.conflicts.0.reason', 'version_mismatch')
            ->assertJsonPath('data.conflicts.0.policy', 'manual_resolution');

        $this->assertSame('Alice', Student::query()->find($student['id'])->name);
    }

    public function test_push_delete_removes_the_student(): void
    {
        $data = $this->register()->json('data');
        $token = $data['access_token'];

        $student = $this->authRequest($token, 'POST', '/api/v1/students', ['name' => 'Alice'])->json('data');

        $this->authRequest($token, 'POST', '/api/v1/sync/push', [
            'operations' => [[
                'operation_id' => 'op-delete-1',
                'entity_type' => 'student',
                'entity_id' => $student['id'],
                'operation' => 'delete',
                'version' => 1,
            ]],
        ])->assertOk()->assertJsonPath('data.applied.0.operation', 'delete');

        $this->assertSoftDeleted('students', ['id' => $student['id']]);
    }

    public function test_push_rejects_unknown_entity_types(): void
    {
        $data = $this->register()->json('data');

        $this->authRequest($data['access_token'], 'POST', '/api/v1/sync/push', [
            'operations' => [[
                'operation_id' => 'op-1',
                'entity_type' => 'widget',
                'entity_id' => (string) Str::ulid(),
                'operation' => 'create',
                'data' => ['name' => 'Nope'],
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('operations.0.entity_type');
    }

    public function test_push_requires_a_version_for_updates(): void
    {
        $data = $this->register()->json('data');

        $this->authRequest($data['access_token'], 'POST', '/api/v1/sync/push', [
            'operations' => [[
                'operation_id' => 'op-1',
                'entity_type' => 'student',
                'entity_id' => (string) Str::ulid(),
                'operation' => 'update',
                'data' => ['name' => 'No version'],
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('operations.0.version');
    }
}
