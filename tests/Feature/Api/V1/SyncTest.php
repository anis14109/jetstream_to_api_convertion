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

    public function test_the_cursor_is_persisted_for_the_client(): void
    {
        $data = $this->register()->json('data');
        $token = $data['access_token'];

        $this->authRequest($token, 'POST', '/api/v1/students', ['name' => 'Alice'])->assertCreated();

        $next = $this->authRequest($token, 'GET', '/api/v1/sync/pull')->json('data.next_cursor');

        $this->authRequest($token, 'GET', '/api/v1/sync/cursor')
            ->assertOk()
            ->assertJsonPath('data.cursor', $next);
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
            ->assertJsonPath('data.conflicts.0.reason', 'version_mismatch');

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
