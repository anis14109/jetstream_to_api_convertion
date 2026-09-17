<?php

namespace App\Services\Api\V1;

use App\Exceptions\ApiException;
use App\Exceptions\ConflictException;
use App\Models\ChangeLog;
use App\Models\Student;
use App\Models\SyncCursor;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Generic offline-first synchronization engine.
 *
 * Pull: returns every change journal entry after the client cursor, ordered by
 * the server revision, and advances the stored cursor for the session.
 *
 * Push: applies a batch of client operations atomically, skipping operations
 * that were already applied (idempotency) and reporting conflicts instead of
 * silently overwriting newer server data.
 */
class SyncService
{
    public function __construct(
        private readonly ChangeTracker $changeTracker,
        private readonly IdempotencyService $idempotency,
        private readonly StudentService $students,
    ) {}

    /**
     * @return array{items: Collection<int, ChangeLog>, next_cursor: int, has_more: bool, server_time: string}
     */
    public function pull(User $user, string $clientId, int $cursor, int $limit): array
    {
        $limit = max(1, min($limit, (int) config('api.sync.pull_batch_size')));

        $changes = ChangeLog::query()
            ->where('user_id', $user->id)
            ->where('id', '>', $cursor)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $changes->count() > $limit;
        $page = $changes->take($limit)->values();
        $nextCursor = (int) ($page->last()?->id ?? $cursor);

        $this->storeCursor($user, $clientId, $nextCursor);

        return [
            'items' => $page,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'server_time' => now()->toISOString(),
        ];
    }

    public function currentCursor(User $user, string $clientId): int
    {
        return (int) (SyncCursor::query()
            ->where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->value('cursor') ?? 0);
    }

    /**
     * @param  array<int, array<string, mixed>>  $operations
     * @return array{applied: array<int, array<string, mixed>>, conflicts: array<int, array<string, mixed>>, replayed: array<int, array<string, mixed>>, latest_revision: int, next_cursor: int, server_time: string}
     */
    public function push(User $user, string $clientId, array $operations): array
    {
        return DB::transaction(function () use ($user, $operations, $clientId): array {
            $applied = [];
            $conflicts = [];
            $replayed = [];
            $latestRevision = 0;

            foreach ($operations as $operation) {
                $existing = $this->idempotency->find($user, $operation['operation_id']);

                if ($existing) {
                    $replayed[] = $existing->response_json;

                    continue;
                }

                try {
                    $result = $this->apply($user, $operation);

                    $this->idempotency->store(
                        $user,
                        $operation['operation_id'],
                        $operation,
                        $operation['entity_type'],
                        $operation['entity_id'],
                        $operation['operation'],
                        200,
                        $result,
                    );

                    $applied[] = $result;
                    $latestRevision = max($latestRevision, (int) $result['revision']);
                } catch (ConflictException $conflict) {
                    $conflicts[] = array_merge($conflict->context, [
                        'operation_id' => $operation['operation_id'],
                        'entity_type' => $operation['entity_type'],
                        'operation' => $operation['operation'],
                        'reason' => $conflict->context['reason'] ?? 'conflict',
                    ]);
                }
            }

            return [
                'applied' => $applied,
                'conflicts' => $conflicts,
                'replayed' => $replayed,
                'latest_revision' => $latestRevision,
                'next_cursor' => $this->currentCursor($user, $clientId),
                'server_time' => now()->toISOString(),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function apply(User $user, array $operation): array
    {
        $entityType = $operation['entity_type'];

        if ($entityType !== StudentService::ENTITY_TYPE) {
            throw ApiException::notFound(sprintf('Unknown entity type [%s].', $entityType));
        }

        $entityId = $operation['entity_id'];
        $data = $operation['data'] ?? [];
        $clientVersion = isset($operation['version']) ? (int) $operation['version'] : null;

        return match ($operation['operation']) {
            'create' => $this->applyCreate($user, $operation, $entityId, $data),
            'update' => $this->applyUpdate($user, $operation, $entityId, $data, $clientVersion),
            'delete' => $this->applyDelete($user, $operation, $entityId, $clientVersion),
            default => throw ApiException::operationFailed('Unsupported sync operation.'),
        };
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyCreate(User $user, array $operation, string $entityId, array $data): array
    {
        $existing = Student::withTrashed()->whereKey($entityId)->first();

        if ($existing) {
            throw ConflictException::forResource([
                'entity_type' => StudentService::ENTITY_TYPE,
                'entity_id' => $entityId,
                'server_version' => $existing->version,
                'server_data' => $existing->toSyncSnapshot(),
                'reason' => 'already_exists',
            ]);
        }

        $result = $this->students->create($user, $data, $entityId);

        return $this->appliedPayload($operation, 'create', $result['student']->version, $result['revision']);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyUpdate(User $user, array $operation, string $entityId, array $data, ?int $clientVersion): array
    {
        $student = Student::withTrashed()->where('user_id', $user->id)->whereKey($entityId)->first();

        if (! $student || $student->trashed()) {
            throw ConflictException::forResource([
                'entity_type' => StudentService::ENTITY_TYPE,
                'entity_id' => $entityId,
                'client_version' => $clientVersion,
                'server_version' => $student?->version,
                'server_data' => $student?->toSyncSnapshot(),
                'reason' => $student?->trashed() ? 'deleted' : 'missing',
            ]);
        }

        $result = $this->students->update($user, $student, $data, $clientVersion);

        return $this->appliedPayload($operation, 'update', $result['student']->version, $result['revision']);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function applyDelete(User $user, array $operation, string $entityId, ?int $clientVersion): array
    {
        $student = Student::withTrashed()->where('user_id', $user->id)->whereKey($entityId)->first();

        if (! $student || $student->trashed()) {
            throw ConflictException::forResource([
                'entity_type' => StudentService::ENTITY_TYPE,
                'entity_id' => $entityId,
                'client_version' => $clientVersion,
                'server_version' => $student?->version,
                'server_data' => $student?->toSyncSnapshot(),
                'reason' => $student?->trashed() ? 'already_deleted' : 'missing',
            ]);
        }

        $result = $this->students->delete($user, $student, $clientVersion);

        return $this->appliedPayload($operation, 'delete', $clientVersion + 1, $result['revision']);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function appliedPayload(array $operation, string $operationName, int $version, int $revision): array
    {
        return [
            'operation_id' => $operation['operation_id'],
            'entity_type' => $operation['entity_type'],
            'entity_id' => $operation['entity_id'],
            'operation' => $operationName,
            'version' => $version,
            'revision' => $revision,
            'server_time' => now()->toISOString(),
        ];
    }

    private function storeCursor(User $user, string $clientId, int $cursor): void
    {
        SyncCursor::query()->updateOrCreate(
            ['user_id' => $user->id, 'client_id' => $clientId],
            ['cursor' => $cursor, 'updated_at' => now()],
        );
    }
}
