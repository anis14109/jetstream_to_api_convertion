<?php

namespace App\Sync\Handlers;

use App\Models\User;
use App\Sync\ConflictPolicy;
use App\Sync\Contracts\SyncResourceHandler;
use App\Sync\SyncApplyResult;

/**
 * Shared conflict-resolution plumbing for resource handlers.
 *
 * Only structural conflicts (a missing record, an already-deleted record or a
 * duplicate create) are always reported to the client. Version mismatches are
 * resolved according to the handler's {@see ConflictPolicy}. Handlers that use
 * {@see ConflictPolicy::ClientWins} or {@see ConflictPolicy::FieldLevelMerge}
 * implement the matching `resolve*` method.
 */
abstract class AbstractSyncResourceHandler implements SyncResourceHandler
{
    public function resolveConflict(
        User $user,
        string $operation,
        string $entityId,
        array $data,
        array $conflictContext,
    ): ?SyncApplyResult {
        $reason = $conflictContext['reason'] ?? null;

        // These are not "stale version" conflicts and must never be silently
        // resolved: the record is gone or already exists.
        if (in_array($reason, ['missing', 'deleted', 'already_deleted', 'already_exists'], true)) {
            return null;
        }

        if ($reason !== 'version_mismatch') {
            return null;
        }

        return match ($this->conflictPolicy()) {
            ConflictPolicy::ServerWins => $this->resolveServerWins($conflictContext),
            ConflictPolicy::ClientWins => $this->resolveClientWins($user, $operation, $entityId, $data),
            ConflictPolicy::FieldLevelMerge => $this->resolveFieldMerge($user, $operation, $entityId, $data),
            ConflictPolicy::ManualResolution => null,
        };
    }

    /**
     * @param  array<string, mixed>  $conflictContext
     */
    protected function resolveServerWins(array $conflictContext): SyncApplyResult
    {
        return new SyncApplyResult(
            version: (int) ($conflictContext['server_version'] ?? 0),
            revision: (int) ($conflictContext['server_revision'] ?? 0),
            resolution: ConflictPolicy::ServerWins,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    abstract protected function resolveClientWins(
        User $user,
        string $operation,
        string $entityId,
        array $data,
    ): ?SyncApplyResult;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function resolveFieldMerge(
        User $user,
        string $operation,
        string $entityId,
        array $data,
    ): ?SyncApplyResult {
        return null;
    }
}
