<?php

namespace App\Sync\Contracts;

use App\Models\User;
use App\Sync\ConflictPolicy;
use App\Sync\SyncApplyResult;

/**
 * A resource-specific participant in the synchronization engine.
 *
 * The engine owns the mechanics shared by every resource (push batching,
 * idempotency, cursors, ACKs, pagination, validation orchestration, journal
 * writes). A handler owns only what differs per resource: its validation
 * rules, business rules and conflict policy.
 *
 * Handlers must delegate to the same domain service used by the REST API so
 * the two never diverge.
 */
interface SyncResourceHandler
{
    /**
     * The stable `entity_type` discriminator used on the wire.
     */
    public function entityType(): string;

    /**
     * The strategy used when a write carries a stale version.
     */
    public function conflictPolicy(): ConflictPolicy;

    /**
     * Validation rules applied to `data` for the given operation.
     *
     * @return array<string, mixed>
     */
    public function dataRules(string $operation): array;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, string $entityId, array $data): SyncApplyResult;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, string $entityId, array $data, int $clientVersion): SyncApplyResult;

    public function delete(User $user, string $entityId, int $clientVersion): SyncApplyResult;

    /**
     * The current server representation of a resource, used to build the
     * conflict payload returned to clients.
     *
     * @return array<string, mixed>|null
     */
    public function snapshot(User $user, string $entityId): ?array;

    /**
     * Apply the resource-specific conflict policy. Returning null reports an
     * unresolved conflict to the client.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $conflictContext
     */
    public function resolveConflict(
        User $user,
        string $operation,
        string $entityId,
        array $data,
        array $conflictContext,
    ): ?SyncApplyResult;
}
