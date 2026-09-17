<?php

namespace App\Services\Api\V1;

use App\Models\ChangeLog;
use App\Models\User;

/**
 * Appends entries to the change journal. Every synchronizable mutation must
 * be recorded here inside the same database transaction that persists the
 * resource so the journal can never drift from the data.
 */
class ChangeTracker
{
    /**
     * Record a change and return the resulting journal entry (whose id is the
     * server revision delivered to clients).
     *
     * @param  array<string, mixed>|null  $data
     */
    public function record(
        User $user,
        string $entityType,
        string $entityId,
        string $operation,
        ?array $data = null,
    ): ChangeLog {
        return ChangeLog::create([
            'user_id' => $user->id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'operation' => $operation,
            'data' => $data,
        ]);
    }

    /**
     * The most recent revision for a user, or zero when nothing changed yet.
     */
    public function latestRevision(User $user): int
    {
        return (int) ChangeLog::query()->where('user_id', $user->id)->max('id');
    }
}
