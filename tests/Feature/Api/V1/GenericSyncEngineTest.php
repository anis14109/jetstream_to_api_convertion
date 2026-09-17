<?php

namespace Tests\Feature\Api\V1;

use App\Services\Api\V1\ChangeTracker;
use App\Sync\ConflictPolicy;
use App\Sync\SyncResourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Api\V1\Concerns\ApiV1Helpers;
use Tests\Support\InMemorySyncResourceHandler;
use Tests\TestCase;

/**
 * Proves the sync engine is resource-agnostic: a second resource is added by
 * registering a handler, without touching the engine, controller or requests.
 */
class GenericSyncEngineTest extends TestCase
{
    use ApiV1Helpers;
    use RefreshDatabase;

    private function registerHandler(ConflictPolicy $policy, string $entityType = 'attendance'): void
    {
        app(SyncResourceRegistry::class)->register(
            new InMemorySyncResourceHandler($entityType, $policy, app(ChangeTracker::class)),
        );
    }

    private function operation(string $entityId, string $operation, array $overrides = []): array
    {
        return array_merge([
            'operation_id' => (string) Str::ulid(),
            'entity_type' => 'attendance',
            'entity_id' => $entityId,
            'operation' => $operation,
            'data' => [],
        ], $overrides);
    }

    public function test_a_registered_resource_is_synchronized_by_the_generic_engine(): void
    {
        $this->registerHandler(ConflictPolicy::ManualResolution);

        $data = $this->register()->json('data');
        $token = $data['access_token'];
        $id = (string) Str::ulid();

        $this->authRequest($token, 'POST', '/api/v1/sync/push', [
            'operations' => [$this->operation($id, 'create', ['data' => ['name' => 'Roll call']])],
        ])->assertOk()->assertJsonPath('data.applied.0.entity_type', 'attendance');

        $pull = $this->authRequest($token, 'GET', '/api/v1/sync/pull');
        $pull->assertOk()->assertJsonPath('data.changes.0.entity_type', 'attendance');
    }

    public function test_server_wins_discards_the_client_write_without_overwriting(): void
    {
        $this->registerHandler(ConflictPolicy::ServerWins);

        $data = $this->register()->json('data');
        $token = $data['access_token'];
        $id = (string) Str::ulid();

        $this->authRequest($token, 'POST', '/api/v1/sync/push', [
            'operations' => [$this->operation($id, 'create', ['data' => ['name' => 'Original']])],
        ])->assertOk();

        $response = $this->authRequest($token, 'POST', '/api/v1/sync/push', [
            'operations' => [$this->operation($id, 'update', [
                'version' => 99,
                'data' => ['name' => 'Client'],
            ])],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.applied.0.resolution', 'server_wins')
            ->assertJsonPath('data.applied.0.version', 1)
            ->assertJsonPath('data.conflicts', []);

        // Server wins appends no journal entry: only the create is recorded.
        $this->assertDatabaseCount('change_logs', 1);
    }

    public function test_client_wins_reapplies_on_top_of_the_server_version(): void
    {
        $this->registerHandler(ConflictPolicy::ClientWins);

        $data = $this->register()->json('data');
        $token = $data['access_token'];
        $id = (string) Str::ulid();

        $this->authRequest($token, 'POST', '/api/v1/sync/push', [
            'operations' => [$this->operation($id, 'create', ['data' => ['name' => 'Original']])],
        ])->assertOk();

        $this->authRequest($token, 'POST', '/api/v1/sync/push', [
            'operations' => [$this->operation($id, 'update', [
                'version' => 99,
                'data' => ['name' => 'Client'],
            ])],
        ])->assertOk()
            ->assertJsonPath('data.applied.0.resolution', 'client_wins')
            ->assertJsonPath('data.applied.0.version', 2);

        $pull = $this->authRequest($token, 'GET', '/api/v1/sync/pull')->json('data.changes');
        $latest = collect($pull)->last();
        $this->assertSame('Client', $latest['data']['name']);
    }

    public function test_field_level_merge_keeps_untouched_server_fields(): void
    {
        $this->registerHandler(ConflictPolicy::FieldLevelMerge);

        $data = $this->register()->json('data');
        $token = $data['access_token'];
        $id = (string) Str::ulid();

        $this->authRequest($token, 'POST', '/api/v1/sync/push', [
            'operations' => [$this->operation($id, 'create', ['data' => ['name' => 'Original', 'note' => 'keep me']])],
        ])->assertOk();

        $this->authRequest($token, 'POST', '/api/v1/sync/push', [
            'operations' => [$this->operation($id, 'update', [
                'version' => 99,
                'data' => ['name' => 'Client'],
            ])],
        ])->assertOk()
            ->assertJsonPath('data.applied.0.resolution', 'field_level_merge');

        $pull = $this->authRequest($token, 'GET', '/api/v1/sync/pull')->json('data.changes');
        $latest = collect($pull)->last();
        $this->assertSame('Client', $latest['data']['name']);
        $this->assertSame('keep me', $latest['data']['note']);
    }

    public function test_structural_conflicts_are_never_silently_resolved(): void
    {
        $this->registerHandler(ConflictPolicy::ClientWins);

        $data = $this->register()->json('data');

        $this->authRequest($data['access_token'], 'POST', '/api/v1/sync/push', [
            'operations' => [$this->operation((string) Str::ulid(), 'update', [
                'version' => 1,
                'data' => ['name' => 'Ghost'],
            ])],
        ])->assertStatus(409)
            ->assertJsonPath('code', 'SYNC_CONFLICT')
            ->assertJsonPath('data.conflicts.0.reason', 'missing');
    }
}
