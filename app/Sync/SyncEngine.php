<?php

namespace App\Sync;

use App\Exceptions\ApiException;
use App\Exceptions\ConflictException;
use App\Models\ChangeLog;
use App\Models\IdempotencyKey;
use App\Models\SyncCursor;
use App\Models\User;
use App\Services\Api\V1\ChangeTracker;
use App\Services\Api\V1\IdempotencyService;
use App\Sync\Contracts\SyncResourceHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Generic offline-first synchronization engine.
 *
 * The engine provides everything that is identical across resources:
 *
 *  - PULL:  stream change-journal entries after a cursor (never acknowledges).
 *  - ACK:   explicitly advance the client's acknowledged checkpoint.
 *  - PUSH:  apply a batch of client operations inside one transaction with
 *           per-operation idempotency and resource-specific conflict policies.
 *
 * Resource-specific behaviour is delegated to {@see SyncResourceHandler}s
 * registered in the {@see SyncResourceRegistry}.
 */
class SyncEngine
{
    public function __construct(
        private readonly ChangeTracker $changeTracker,
        private readonly IdempotencyService $idempotency,
        private readonly SyncResourceRegistry $registry,
    ) {}

    /**
     * The client's synchronization checkpoints.
     *
     * `cursor` is kept as an alias of `acknowledged_cursor` for backwards
     * compatibility with existing v1 clients.
     *
     * @return array{cursor: int, acknowledged_cursor: int, last_pulled_cursor: int, server_time: string}
     */
    public function currentCursor(User $user, string $clientId): array
    {
        $cursor = $this->cursor($user, $clientId);

        return [
            'cursor' => $cursor->acknowledged_cursor,
            'acknowledged_cursor' => $cursor->acknowledged_cursor,
            'last_pulled_cursor' => $cursor->last_pulled_cursor,
            'server_time' => now()->toISOString(),
        ];
    }

    /**
     * Return journal entries after `cursor`. Pulling records how far the client
     * has been given data but never advances the acknowledged checkpoint.
     *
     * @return array{items: Collection<int, ChangeLog>, next_cursor: int, has_more: bool, server_time: string}
     */
    public function pull(User $user, string $clientId, ?int $requestedCursor, int $limit): array
    {
        $syncCursor = $this->cursor($user, $clientId);
        $latest = $this->changeTracker->latestRevision($user);

        $start = $requestedCursor ?? $syncCursor->acknowledged_cursor;
        $start = max(0, min($start, $latest));

        $limit = max(1, min($limit, (int) config('api.sync.pull_batch_size')));

        $changes = ChangeLog::query()
            ->where('user_id', $user->id)
            ->where('id', '>', $start)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $hasMore = $changes->count() > $limit;
        $page = $changes->take($limit)->values();
        $nextCursor = (int) ($page->last()?->id ?? $start);

        // "Pulling" means the changes were delivered; it does not mean the
        // client applied them. Only the delivered revision range is recorded.
        $delivered = max($start, $nextCursor);
        if ($delivered > $syncCursor->last_pulled_cursor) {
            $this->updateCursor($user, $clientId, ['last_pulled_cursor' => $delivered]);
        }

        return [
            'items' => $page,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'server_time' => now()->toISOString(),
        ];
    }

    /**
     * Explicitly acknowledge that the client has applied every change up to
     * `cursor`. Idempotent: re-acknowledging at or below the stored checkpoint
     * succeeds without changing anything.
     *
     * @return array{acknowledged_cursor: int, last_pulled_cursor: int, server_time: string}
     */
    public function ack(User $user, string $clientId, int $cursor): array
    {
        $syncCursor = $this->cursor($user, $clientId);

        if ($cursor < 0) {
            throw ApiException::validation(['cursor' => ['The cursor must be a non-negative integer.']]);
        }

        // Stale/duplicate ACK: already applied, nothing to do.
        if ($cursor <= $syncCursor->acknowledged_cursor) {
            return $this->ackPayload($syncCursor);
        }

        // A client may only acknowledge changes that have been delivered to it.
        if ($cursor > $syncCursor->last_pulled_cursor) {
            throw ApiException::validation(
                ['cursor' => ['The cursor is ahead of the last change delivered to this client. Pull before acknowledging.']],
                'The cursor is invalid for this client.',
            );
        }

        $this->updateCursor($user, $clientId, ['acknowledged_cursor' => $cursor]);

        return $this->ackPayload($this->cursor($user, $clientId));
    }

    /**
     * Apply a batch of client operations atomically.
     *
     * @param  array<int, array<string, mixed>>  $operations
     * @return array{applied: array<int, array<string, mixed>>, conflicts: array<int, array<string, mixed>>, idempotency_conflicts: array<int, array<string, mixed>>, pending: array<int, array<string, mixed>>, replayed: array<int, array<string, mixed>>, latest_revision: int, next_cursor: int, server_time: string}
     */
    public function push(User $user, string $clientId, array $operations): array
    {
        return DB::transaction(function () use ($user, $operations, $clientId): array {
            $applied = [];
            $conflicts = [];
            $idempotencyConflicts = [];
            $pending = [];
            $replayed = [];
            $latestRevision = 0;

            foreach ($operations as $operation) {
                $fingerprint = $this->idempotency->fingerprint($operation);

                $begin = $this->idempotency->begin(
                    $user,
                    $operation['operation_id'],
                    $fingerprint,
                    $operation['entity_type'],
                    $operation['entity_id'],
                    $operation['operation'],
                );

                if ($begin['status'] === 'replay') {
                    $replayed[] = $begin['response'];

                    continue;
                }

                if ($begin['status'] === 'conflict') {
                    $idempotencyConflicts[] = $this->idempotencyEntry($operation, 'idempotency_conflict');

                    continue;
                }

                if ($begin['status'] === 'pending') {
                    $pending[] = $this->idempotencyEntry($operation, 'in_progress');

                    continue;
                }

                /** @var IdempotencyKey $key */
                $key = $begin['key'];

                try {
                    $result = $this->apply(
                        $user,
                        $operation['entity_type'],
                        $operation['operation'],
                        $operation['entity_id'],
                        $operation['data'] ?? [],
                        isset($operation['version']) ? (int) $operation['version'] : null,
                    );
                } catch (ConflictException $conflict) {
                    $resolution = $this->resolveConflict($user, $operation, $conflict);

                    if ($resolution === null) {
                        $this->idempotency->release($key);
                        $conflicts[] = $this->conflictEntry($operation, $conflict);

                        continue;
                    }

                    $result = $resolution;
                }

                $payload = $this->appliedPayload($operation, $result);
                $this->idempotency->complete($key, 200, $payload);

                $applied[] = $payload;
                $latestRevision = max($latestRevision, $result->revision);
            }

            $cursor = $this->cursor($user, $clientId);

            return [
                'applied' => $applied,
                'conflicts' => $conflicts,
                'idempotency_conflicts' => $idempotencyConflicts,
                'pending' => $pending,
                'replayed' => $replayed,
                'latest_revision' => $latestRevision,
                'next_cursor' => $cursor->last_pulled_cursor,
                'acknowledged_cursor' => $cursor->acknowledged_cursor,
                'server_time' => now()->toISOString(),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function apply(
        User $user,
        string $entityType,
        string $operation,
        string $entityId,
        array $data,
        ?int $clientVersion,
    ): SyncApplyResult {
        $handler = $this->registry->get($entityType);

        return match ($operation) {
            'create' => $handler->create($user, $entityId, $data),
            'update' => $handler->update($user, $entityId, $data, (int) $clientVersion),
            'delete' => $handler->delete($user, $entityId, (int) $clientVersion),
            default => throw ApiException::operationFailed('Unsupported sync operation.'),
        };
    }

    /**
     * @param  array<string, mixed>  $operation
     */
    private function resolveConflict(User $user, array $operation, ConflictException $conflict): ?SyncApplyResult
    {
        $handler = $this->registry->get($operation['entity_type']);

        return $handler->resolveConflict(
            $user,
            $operation['operation'],
            $operation['entity_id'],
            $operation['data'] ?? [],
            $conflict->context,
        );
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function appliedPayload(array $operation, SyncApplyResult $result): array
    {
        $payload = [
            'operation_id' => $operation['operation_id'],
            'entity_type' => $operation['entity_type'],
            'entity_id' => $operation['entity_id'],
            'operation' => $operation['operation'],
            'version' => $result->version,
            'revision' => $result->revision,
            'server_time' => now()->toISOString(),
        ];

        if ($result->resolution !== null) {
            $payload['resolution'] = $result->resolution->value;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function conflictEntry(array $operation, ConflictException $conflict): array
    {
        return array_merge($conflict->context, [
            'operation_id' => $operation['operation_id'],
            'entity_type' => $operation['entity_type'],
            'operation' => $operation['operation'],
            'reason' => $conflict->context['reason'] ?? 'conflict',
            'policy' => $this->registry->get($operation['entity_type'])->conflictPolicy()->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function idempotencyEntry(array $operation, string $reason): array
    {
        return [
            'operation_id' => $operation['operation_id'],
            'entity_type' => $operation['entity_type'],
            'operation' => $operation['operation'],
            'reason' => $reason,
        ];
    }

    /**
     * @return array{acknowledged_cursor: int, last_pulled_cursor: int, server_time: string}
     */
    private function ackPayload(SyncCursor $cursor): array
    {
        return [
            'acknowledged_cursor' => $cursor->acknowledged_cursor,
            'last_pulled_cursor' => $cursor->last_pulled_cursor,
            'server_time' => now()->toISOString(),
        ];
    }

    private function cursor(User $user, string $clientId): SyncCursor
    {
        try {
            return SyncCursor::firstOrCreate(
                ['user_id' => $user->id, 'client_id' => $clientId],
                ['last_pulled_cursor' => 0, 'acknowledged_cursor' => 0],
            );
        } catch (QueryException) {
            // Lost a race creating the row; the unique index guarantees one exists.
            return SyncCursor::query()
                ->where('user_id', $user->id)
                ->where('client_id', $clientId)
                ->firstOrFail();
        }
    }

    /**
     * @param  array<string, int>  $attributes
     */
    private function updateCursor(User $user, string $clientId, array $attributes): void
    {
        SyncCursor::query()
            ->where('user_id', $user->id)
            ->where('client_id', $clientId)
            ->update($attributes + ['updated_at' => now()]);
    }
}
