<?php

namespace App\Services\Api\V1;

use App\Exceptions\ConflictException;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The single authoritative implementation for creating, updating and deleting
 * students. Both the REST endpoints and the sync push pipeline call here so
 * versioning and change tracking can never diverge.
 */
class StudentService
{
    public const ENTITY_TYPE = 'student';

    private const MUTABLE_FIELDS = ['name', 'email', 'notes'];

    public function __construct(private readonly ChangeTracker $changeTracker) {}

    /**
     * @param  array{name: string, email?: string|null, notes?: string|null}  $data
     * @return array{student: Student, revision: int}
     */
    public function create(User $user, array $data, ?string $id = null): array
    {
        return DB::transaction(function () use ($user, $data, $id): array {
            if ($id !== null && Student::withTrashed()->whereKey($id)->exists()) {
                // The identifier is already taken. The sync handler reports the
                // owner's own record with a snapshot; this guard covers the REST
                // path and cross-user collisions without leaking any data.
                throw ConflictException::forResource([
                    'entity_type' => self::ENTITY_TYPE,
                    'entity_id' => $id,
                    'reason' => 'already_exists',
                ]);
            }

            $student = new Student;

            if ($id !== null) {
                $student->id = $id;
            }

            $student->fill([
                'user_id' => $user->id,
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'notes' => $data['notes'] ?? null,
                'version' => 1,
            ])->save();

            $change = $this->changeTracker->record(
                $user,
                self::ENTITY_TYPE,
                $student->id,
                'created',
                $student->toSyncSnapshot(),
            );

            return ['student' => $student->refresh(), 'revision' => $change->revision];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{student: Student, revision: int}
     */
    public function update(User $user, Student $student, array $data, ?int $expectedVersion = null): array
    {
        return DB::transaction(function () use ($user, $student, $data, $expectedVersion): array {
            $expected = $expectedVersion ?? $student->version;
            $attributes = array_merge(
                Arr::only($data, self::MUTABLE_FIELDS),
                ['version' => $expected + 1, 'updated_at' => now()],
            );

            $affected = Student::query()
                ->where('user_id', $user->id)
                ->whereKey($student->id)
                ->where('version', $expected)
                ->update($attributes);

            if ($affected !== 1) {
                $current = Student::withTrashed()
                    ->where('user_id', $user->id)
                    ->find($student->id);

                throw ConflictException::forResource([
                    'entity_type' => self::ENTITY_TYPE,
                    'entity_id' => $student->id,
                    'client_version' => $expected,
                    'server_version' => $current?->version,
                    'server_data' => $current?->toSyncSnapshot(),
                    'reason' => 'version_mismatch',
                ]);
            }

            $student->refresh();

            $change = $this->changeTracker->record(
                $user,
                self::ENTITY_TYPE,
                $student->id,
                'updated',
                $student->toSyncSnapshot(),
            );

            return ['student' => $student, 'revision' => $change->revision];
        });
    }

    /**
     * @return array{revision: int}
     */
    public function delete(User $user, Student $student, ?int $expectedVersion = null): array
    {
        return DB::transaction(function () use ($user, $student, $expectedVersion): array {
            $expected = $expectedVersion ?? $student->version;
            $deletedAt = now();

            $affected = Student::query()
                ->where('user_id', $user->id)
                ->whereKey($student->id)
                ->where('version', $expected)
                ->update([
                    'version' => $expected + 1,
                    'deleted_at' => $deletedAt,
                    'updated_at' => $deletedAt,
                ]);

            if ($affected !== 1) {
                $current = Student::withTrashed()
                    ->where('user_id', $user->id)
                    ->find($student->id);

                throw ConflictException::forResource([
                    'entity_type' => self::ENTITY_TYPE,
                    'entity_id' => $student->id,
                    'client_version' => $expected,
                    'server_version' => $current?->version,
                    'server_data' => $current?->toSyncSnapshot(),
                    'reason' => 'version_mismatch',
                ]);
            }

            $change = $this->changeTracker->record($user, self::ENTITY_TYPE, $student->id, 'deleted', [
                'id' => $student->id,
                'version' => $expected + 1,
                'deleted_at' => $deletedAt->toISOString(),
            ]);

            return ['revision' => $change->revision];
        });
    }
}
