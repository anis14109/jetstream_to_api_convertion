<?php

namespace App\Services\Api\V1;

use App\Models\IdempotencyKey;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Guarantees that a retried mutation is applied at most once.
 *
 * A client supplies an `operation_id`. We hash it together with the user id,
 * persist a deterministic fingerprint of the request payload and the outcome
 * of the first execution, then:
 *
 *   same operation id + same payload   -> replay the stored response
 *   same operation id + different body -> idempotency conflict (409)
 *
 * Concurrency safety comes from a unique index on (user_id, key_hash) plus a
 * reservation row written before the business operation runs. A second request
 * arriving at the same moment fails to reserve, observes the in-flight row and
 * must not execute the operation.
 */
class IdempotencyService
{
    public function hashOperation(User $user, string $operationId): string
    {
        return hash('sha256', $user->id.'|'.$operationId);
    }

    /**
     * A stable fingerprint of the request payload. Keys are sorted recursively
     * so logically identical payloads always hash identically regardless of how
     * the JSON was ordered on the wire.
     *
     * @param  array<string, mixed>  $payload
     */
    public function fingerprint(array $payload): string
    {
        return hash('sha256', (string) json_encode($this->canonicalize($payload)));
    }

    public function find(User $user, string $operationId): ?IdempotencyKey
    {
        return IdempotencyKey::query()
            ->where('user_id', $user->id)
            ->where('key_hash', $this->hashOperation($user, $operationId))
            ->first();
    }

    /**
     * A snapshot-safe lookup used after a unique-constraint violation. A plain
     * SELECT can use a transaction's older consistent snapshot and miss the row
     * the winning request just committed, so we force a locking read that sees
     * the latest committed state.
     */
    private function findForUpdate(User $user, string $operationId): ?IdempotencyKey
    {
        return IdempotencyKey::query()
            ->where('user_id', $user->id)
            ->where('key_hash', $this->hashOperation($user, $operationId))
            ->lockForUpdate()
            ->first();
    }

    /**
     * Reserve the operation id before executing the business operation.
     *
     * The INSERT is wrapped in its own (nested) transaction so that, on
     * databases that abort a transaction after a constraint violation
     * (PostgreSQL), the failed reservation is rolled back to a savepoint and the
     * surrounding push transaction stays usable. The unique index on
     * (user_id, key_hash) is the real arbiter: exactly one concurrent request
     * can insert the row, every other request observes the in-flight or
     * completed record instead.
     *
     * @return array{status: 'new'|'replay'|'conflict'|'pending', key: ?IdempotencyKey, response: ?array<string, mixed>}
     */
    public function begin(
        User $user,
        string $operationId,
        string $fingerprint,
        string $entityType,
        string $entityId,
        string $operation,
    ): array {
        try {
            $key = DB::transaction(fn (): IdempotencyKey => IdempotencyKey::create([
                'user_id' => $user->id,
                'key_hash' => $this->hashOperation($user, $operationId),
                'request_hash' => $fingerprint,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'operation' => $operation,
                'response_code' => 200,
                'response_json' => null,
                'completed_at' => null,
            ]));

            return ['status' => 'new', 'key' => $key, 'response' => null];
        } catch (QueryException $exception) {
            // The unique (user_id, key_hash) index fired: another request owns
            // this operation id. Inspect its state to decide how to respond.
            $existing = $this->findForUpdate($user, $operationId);

            if (! $existing) {
                throw $exception;
            }

            return $this->inspect($existing, $fingerprint);
        }
    }

    /**
     * Persist the response of a successful operation so later retries replay it.
     *
     * @param  array<string, mixed>  $response
     */
    public function complete(IdempotencyKey $key, int $responseCode, array $response): void
    {
        $key->forceFill([
            'response_code' => $responseCode,
            'response_json' => $response,
            'completed_at' => now(),
        ])->save();
    }

    /**
     * Release a reservation when the operation did not produce a business
     * result (for example a version conflict) so the client may retry it.
     */
    public function release(IdempotencyKey $key): void
    {
        $key->delete();
    }

    /**
     * @return array{status: 'replay'|'conflict'|'pending', key: IdempotencyKey, response: ?array<string, mixed>}
     */
    private function inspect(IdempotencyKey $existing, string $fingerprint): array
    {
        if (! hash_equals($existing->request_hash, $fingerprint)) {
            return ['status' => 'conflict', 'key' => $existing, 'response' => null];
        }

        if ($existing->isPending()) {
            return ['status' => 'pending', 'key' => $existing, 'response' => null];
        }

        return ['status' => 'replay', 'key' => $existing, 'response' => $existing->response_json];
    }

    /**
     * Recursively sort associative arrays by key so the JSON encoding is
     * deterministic. List arrays keep their order (order is meaningful).
     *
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function canonicalize(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn ($item) => is_array($item) ? $this->canonicalize($item) : $item,
                $value,
            );
        }

        ksort($value);

        return array_map(
            fn ($item) => is_array($item) ? $this->canonicalize($item) : $item,
            $value,
        );
    }
}
