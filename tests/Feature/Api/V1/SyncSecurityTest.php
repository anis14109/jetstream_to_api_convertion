<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\Concerns\ApiV1Helpers;
use Tests\TestCase;

/**
 * Cross-user isolation: no user may read, write or acknowledge another user's
 * data, and conflict payloads must never leak another user's server snapshot.
 */
class SyncSecurityTest extends TestCase
{
    use ApiV1Helpers;
    use RefreshDatabase;

    /**
     * @return array{owner: array<string, mixed>, student: array<string, mixed>}
     */
    private function ownerWithStudent(): array
    {
        $owner = $this->register(['email' => 'owner@example.com'])->json('data');
        $student = $this->authRequest($owner['access_token'], 'POST', '/api/v1/students', [
            'name' => 'Owner Student',
        ])->json('data');

        return ['owner' => $owner, 'student' => $student];
    }

    private function attacker(): array
    {
        return $this->register(['email' => 'attacker@example.com'])->json('data');
    }

    public function test_a_user_cannot_view_another_users_student(): void
    {
        $context = $this->ownerWithStudent();
        $attacker = $this->attacker();

        $this->forgetAuthGuard();

        $this->authRequest($attacker['access_token'], 'GET', '/api/v1/students/'.$context['student']['id'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_a_user_cannot_update_another_users_student(): void
    {
        $context = $this->ownerWithStudent();
        $attacker = $this->attacker();

        $this->forgetAuthGuard();

        $this->authRequest($attacker['access_token'], 'PATCH', '/api/v1/students/'.$context['student']['id'], [
            'name' => 'Hijacked',
            'version' => 1,
        ])->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');

        $this->assertDatabaseHas('students', [
            'id' => $context['student']['id'],
            'name' => 'Owner Student',
        ]);
    }

    public function test_a_user_cannot_delete_another_users_student(): void
    {
        $context = $this->ownerWithStudent();
        $attacker = $this->attacker();

        $this->forgetAuthGuard();

        $this->authRequest($attacker['access_token'], 'DELETE', '/api/v1/students/'.$context['student']['id'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->assertDatabaseHas('students', [
            'id' => $context['student']['id'],
            'deleted_at' => null,
        ]);
    }

    public function test_a_user_only_pulls_their_own_changes(): void
    {
        $this->ownerWithStudent();
        $attacker = $this->attacker();

        $this->forgetAuthGuard();

        $pull = $this->authRequest($attacker['access_token'], 'GET', '/api/v1/sync/pull');
        $pull->assertOk();
        $this->assertCount(0, $pull->json('data.changes'));
        $this->assertSame(0, $pull->json('data.next_cursor'));
    }

    public function test_a_sync_update_cannot_target_another_users_entity(): void
    {
        $context = $this->ownerWithStudent();
        $attacker = $this->attacker();

        $this->forgetAuthGuard();

        $response = $this->authRequest($attacker['access_token'], 'POST', '/api/v1/sync/push', [
            'operations' => [[
                'operation_id' => 'op-cross-update',
                'entity_type' => 'student',
                'entity_id' => $context['student']['id'],
                'operation' => 'update',
                'version' => 1,
                'data' => ['name' => 'Hijacked'],
            ]],
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('data.conflicts.0.reason', 'missing');

        $this->assertNull($response->json('data.conflicts.0.server_data'));
        $this->assertDatabaseHas('students', [
            'id' => $context['student']['id'],
            'name' => 'Owner Student',
        ]);
    }

    public function test_a_sync_delete_cannot_target_another_users_entity(): void
    {
        $context = $this->ownerWithStudent();
        $attacker = $this->attacker();

        $this->forgetAuthGuard();

        $response = $this->authRequest($attacker['access_token'], 'POST', '/api/v1/sync/push', [
            'operations' => [[
                'operation_id' => 'op-cross-delete',
                'entity_type' => 'student',
                'entity_id' => $context['student']['id'],
                'operation' => 'delete',
                'version' => 1,
            ]],
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('data.conflicts.0.reason', 'missing');

        $this->assertNull($response->json('data.conflicts.0.server_data'));
        $this->assertDatabaseHas('students', [
            'id' => $context['student']['id'],
            'deleted_at' => null,
        ]);
    }

    public function test_a_sync_create_cannot_leak_another_users_snapshot(): void
    {
        $context = $this->ownerWithStudent();
        $attacker = $this->attacker();

        $this->forgetAuthGuard();

        $response = $this->authRequest($attacker['access_token'], 'POST', '/api/v1/sync/push', [
            'operations' => [[
                'operation_id' => 'op-cross-create',
                'entity_type' => 'student',
                'entity_id' => $context['student']['id'],
                'operation' => 'create',
                'data' => ['name' => 'Stolen'],
            ]],
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('data.conflicts.0.reason', 'already_exists');

        $this->assertArrayNotHasKey('server_data', $response->json('data.conflicts.0'));
    }

    public function test_acknowledgements_are_scoped_per_user(): void
    {
        $context = $this->ownerWithStudent();

        $this->authRequest($context['owner']['access_token'], 'GET', '/api/v1/sync/pull');
        $this->authRequest($context['owner']['access_token'], 'POST', '/api/v1/sync/ack', ['cursor' => 1])
            ->assertOk()
            ->assertJsonPath('data.acknowledged_cursor', 1);

        $attacker = $this->attacker();

        $this->forgetAuthGuard();

        $this->authRequest($attacker['access_token'], 'GET', '/api/v1/sync/cursor')
            ->assertOk()
            ->assertJsonPath('data.acknowledged_cursor', 0);

        $this->authRequest($attacker['access_token'], 'POST', '/api/v1/sync/ack', ['cursor' => 1])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_ERROR');
    }

    public function test_a_user_cannot_create_a_student_with_another_users_identifier_via_rest(): void
    {
        $context = $this->ownerWithStudent();
        $attacker = $this->attacker();

        $this->forgetAuthGuard();

        $this->authRequest($attacker['access_token'], 'POST', '/api/v1/students', [
            'id' => $context['student']['id'],
            'name' => 'Stolen',
        ])->assertStatus(409)->assertJsonPath('code', 'SYNC_CONFLICT');

        $this->assertDatabaseHas('students', [
            'id' => $context['student']['id'],
            'name' => 'Owner Student',
        ]);
    }

    public function test_students_are_listed_per_user(): void
    {
        $this->ownerWithStudent();
        $attacker = $this->attacker();

        $this->forgetAuthGuard();

        $list = $this->authRequest($attacker['access_token'], 'GET', '/api/v1/students');
        $list->assertOk()->assertJsonPath('data.items', []);
    }

    public function test_an_unsupported_entity_type_is_rejected_before_any_work(): void
    {
        $data = $this->register()->json('data');

        $this->authRequest($data['access_token'], 'POST', '/api/v1/sync/push', [
            'operations' => [[
                'operation_id' => (string) Str::ulid(),
                'entity_type' => 'users',
                'entity_id' => (string) Str::ulid(),
                'operation' => 'create',
                'data' => ['name' => 'Nope'],
            ]],
        ])->assertStatus(422)->assertJsonValidationErrors('operations.0.entity_type');
    }
}
