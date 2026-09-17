<?php

namespace App\Sync\Handlers;

use App\Exceptions\ConflictException;
use App\Models\Student;
use App\Models\User;
use App\Services\Api\V1\StudentService;
use App\Sync\ConflictPolicy;
use App\Sync\SyncApplyResult;
use Illuminate\Support\Arr;

/**
 * Synchronization handler for the Student resource.
 *
 * All business rules live in {@see StudentService}, which is shared with the
 * REST API. This handler only adapts that service to the sync contract and
 * declares the resource's conflict policy.
 */
final class StudentSyncHandler extends AbstractSyncResourceHandler
{
    private const MUTABLE_FIELDS = ['name', 'email', 'notes'];

    public function __construct(private readonly StudentService $students) {}

    public function entityType(): string
    {
        return StudentService::ENTITY_TYPE;
    }

    /**
     * Students are permanent business records and may be edited by multiple
     * devices, so a stale write is surfaced to the client rather than silently
     * resolved.
     */
    public function conflictPolicy(): ConflictPolicy
    {
        return ConflictPolicy::ManualResolution;
    }

    public function dataRules(string $operation): array
    {
        return match ($operation) {
            'create', 'update' => [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['nullable', 'string', 'email', 'max:255'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
            default => [
                'name' => ['nullable', 'string', 'max:255'],
                'email' => ['nullable', 'string', 'email', 'max:255'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
        };
    }

    public function create(User $user, string $entityId, array $data): SyncApplyResult
    {
        $existing = $this->find($user, $entityId);

        if ($existing) {
            throw ConflictException::forResource([
                'entity_type' => $this->entityType(),
                'entity_id' => $entityId,
                'server_version' => $existing->version,
                'server_data' => $existing->toSyncSnapshot(),
                'reason' => 'already_exists',
            ]);
        }

        // The id belongs to another user. Report the collision without leaking
        // that record's contents.
        if (Student::withTrashed()->whereKey($entityId)->exists()) {
            throw ConflictException::forResource([
                'entity_type' => $this->entityType(),
                'entity_id' => $entityId,
                'reason' => 'already_exists',
            ]);
        }

        $result = $this->students->create($user, $data, $entityId);

        return new SyncApplyResult($result['student']->version, $result['revision']);
    }

    public function update(User $user, string $entityId, array $data, int $clientVersion): SyncApplyResult
    {
        $student = $this->find($user, $entityId);

        if (! $student || $student->trashed()) {
            throw $this->missingConflict($student, $entityId, $clientVersion);
        }

        $result = $this->students->update($user, $student, $data, $clientVersion);

        return new SyncApplyResult($result['student']->version, $result['revision']);
    }

    public function delete(User $user, string $entityId, int $clientVersion): SyncApplyResult
    {
        $student = $this->find($user, $entityId);

        if (! $student || $student->trashed()) {
            throw $this->missingConflict($student, $entityId, $clientVersion);
        }

        $result = $this->students->delete($user, $student, $clientVersion);

        return new SyncApplyResult($clientVersion + 1, $result['revision']);
    }

    public function snapshot(User $user, string $entityId): ?array
    {
        return $this->find($user, $entityId)?->toSyncSnapshot();
    }

    protected function resolveClientWins(
        User $user,
        string $operation,
        string $entityId,
        array $data,
    ): ?SyncApplyResult {
        $student = $this->find($user, $entityId);

        if (! $student || $student->trashed()) {
            return null;
        }

        if ($operation === 'delete') {
            $result = $this->students->delete($user, $student, $student->version);

            return new SyncApplyResult($student->version + 1, $result['revision'], ConflictPolicy::ClientWins);
        }

        $result = $this->students->update($user, $student, $data, $student->version);

        return new SyncApplyResult($result['student']->version, $result['revision'], ConflictPolicy::ClientWins);
    }

    protected function resolveFieldMerge(
        User $user,
        string $operation,
        string $entityId,
        array $data,
    ): ?SyncApplyResult {
        if ($operation === 'delete') {
            return null;
        }

        $student = $this->find($user, $entityId);

        if (! $student || $student->trashed()) {
            return null;
        }

        $merged = array_merge(
            Arr::only($student->toSyncSnapshot(), self::MUTABLE_FIELDS),
            Arr::only($data, self::MUTABLE_FIELDS),
        );

        $result = $this->students->update($user, $student, $merged, $student->version);

        return new SyncApplyResult($result['student']->version, $result['revision'], ConflictPolicy::FieldLevelMerge);
    }

    private function find(User $user, string $entityId): ?Student
    {
        return Student::withTrashed()
            ->where('user_id', $user->id)
            ->whereKey($entityId)
            ->first();
    }

    private function missingConflict(?Student $student, string $entityId, ?int $clientVersion): ConflictException
    {
        return ConflictException::forResource([
            'entity_type' => $this->entityType(),
            'entity_id' => $entityId,
            'client_version' => $clientVersion,
            'server_version' => $student?->version,
            'server_data' => $student?->toSyncSnapshot(),
            'reason' => $student?->trashed() ? 'deleted' : 'missing',
        ]);
    }
}
