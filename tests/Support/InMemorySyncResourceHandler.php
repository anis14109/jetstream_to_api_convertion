<?php

namespace Tests\Support;

use App\Exceptions\ConflictException;
use App\Models\User;
use App\Services\Api\V1\ChangeTracker;
use App\Sync\ConflictPolicy;
use App\Sync\Handlers\AbstractSyncResourceHandler;
use App\Sync\SyncApplyResult;

/**
 * A minimal, database-free synchronization resource used to prove that the
 * engine and the conflict policies are generic. It behaves like a real handler
 * (versioned records, change-journal entries, conflict reporting) without
 * adding a second persistent domain to the application.
 */
class InMemorySyncResourceHandler extends AbstractSyncResourceHandler
{
    /** @var array<string, array<string, mixed>> */
    private array $records = [];

    public function __construct(
        private readonly string $entityType,
        private readonly ConflictPolicy $policy,
        private readonly ChangeTracker $changeTracker,
    ) {}

    public function entityType(): string
    {
        return $this->entityType;
    }

    public function conflictPolicy(): ConflictPolicy
    {
        return $this->policy;
    }

    public function dataRules(string $operation): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function create(User $user, string $entityId, array $data): SyncApplyResult
    {
        if (isset($this->records[$entityId])) {
            throw ConflictException::forResource([
                'entity_type' => $this->entityType,
                'entity_id' => $entityId,
                'server_version' => $this->records[$entityId]['version'],
                'server_data' => $this->records[$entityId],
                'reason' => 'already_exists',
            ]);
        }

        return $this->store($user, $entityId, $data, 1);
    }

    public function update(User $user, string $entityId, array $data, int $clientVersion): SyncApplyResult
    {
        $current = $this->records[$entityId] ?? null;

        if ($current === null) {
            throw ConflictException::forResource([
                'entity_type' => $this->entityType,
                'entity_id' => $entityId,
                'client_version' => $clientVersion,
                'reason' => 'missing',
            ]);
        }

        if ($current['version'] !== $clientVersion) {
            throw ConflictException::forResource([
                'entity_type' => $this->entityType,
                'entity_id' => $entityId,
                'client_version' => $clientVersion,
                'server_version' => $current['version'],
                'server_data' => $current,
                'reason' => 'version_mismatch',
            ]);
        }

        return $this->store($user, $entityId, array_merge($current, $data), $current['version'] + 1);
    }

    public function delete(User $user, string $entityId, int $clientVersion): SyncApplyResult
    {
        $current = $this->records[$entityId] ?? null;

        if ($current === null) {
            throw ConflictException::forResource([
                'entity_type' => $this->entityType,
                'entity_id' => $entityId,
                'client_version' => $clientVersion,
                'reason' => 'missing',
            ]);
        }

        if ($current['version'] !== $clientVersion) {
            throw ConflictException::forResource([
                'entity_type' => $this->entityType,
                'entity_id' => $entityId,
                'client_version' => $clientVersion,
                'server_version' => $current['version'],
                'server_data' => $current,
                'reason' => 'version_mismatch',
            ]);
        }

        unset($this->records[$entityId]);

        $change = $this->changeTracker->record($user, $this->entityType, $entityId, 'deleted', [
            'id' => $entityId,
            'version' => $current['version'] + 1,
        ]);

        return new SyncApplyResult($current['version'] + 1, $change->revision);
    }

    public function snapshot(User $user, string $entityId): ?array
    {
        return $this->records[$entityId] ?? null;
    }

    protected function resolveClientWins(User $user, string $operation, string $entityId, array $data): ?SyncApplyResult
    {
        $current = $this->records[$entityId] ?? null;

        if ($current === null) {
            return null;
        }

        if ($operation === 'delete') {
            return $this->forceDelete($user, $entityId, $current);
        }

        return $this->store($user, $entityId, array_merge($current, $data), $current['version'] + 1, ConflictPolicy::ClientWins);
    }

    protected function resolveFieldMerge(User $user, string $operation, string $entityId, array $data): ?SyncApplyResult
    {
        if ($operation === 'delete' || ! isset($this->records[$entityId])) {
            return null;
        }

        $merged = array_merge($this->records[$entityId], array_filter($data, fn ($value) => $value !== null));

        return $this->store($user, $entityId, $merged, $this->records[$entityId]['version'] + 1, ConflictPolicy::FieldLevelMerge);
    }

    /**
     * @param  array<string, mixed>  $current
     */
    private function forceDelete(User $user, string $entityId, array $current): SyncApplyResult
    {
        unset($this->records[$entityId]);

        $change = $this->changeTracker->record($user, $this->entityType, $entityId, 'deleted', [
            'id' => $entityId,
            'version' => $current['version'] + 1,
        ]);

        return new SyncApplyResult($current['version'] + 1, $change->revision, ConflictPolicy::ClientWins);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function store(
        User $user,
        string $entityId,
        array $data,
        int $version,
        ?ConflictPolicy $resolution = null,
    ): SyncApplyResult {
        $record = array_merge(['id' => $entityId], $data, ['version' => $version]);
        $this->records[$entityId] = $record;

        $change = $this->changeTracker->record(
            $user,
            $this->entityType,
            $entityId,
            $version === 1 ? 'created' : 'updated',
            $record,
        );

        return new SyncApplyResult($version, $change->revision, $resolution);
    }
}
