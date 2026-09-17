<?php

namespace App\Services\Api\V1;

use App\Models\IdempotencyKey;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * Guarantees that a retried write operation is applied at most once.
 *
 * A client supplies an `operation_id`. We hash it together with the user id,
 * persist the outcome of the first successful execution and replay that exact
 * outcome for any later request carrying the same id.
 */
class IdempotencyService
{
    public function hashOperation(User $user, string $operationId): string
    {
        return hash('sha256', $user->id.'|'.$operationId);
    }

    /**
     * A stable fingerprint of the request payload used to detect a reused
     * operation id with a different body.
     *
     * @param  array<string, mixed>  $payload
     */
    public function fingerprint(array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode($payload) ?: '');
    }

    public function find(User $user, string $operationId): ?IdempotencyKey
    {
        return IdempotencyKey::query()
            ->where('user_id', $user->id)
            ->where('key_hash', $this->hashOperation($user, $operationId))
            ->first();
    }

    /**
     * Persist the result of an operation. Returns the existing record when a
     * concurrent request won the race, making the endpoint safe under retries.
     *
     * @param  array<string, mixed>  $fingerprintPayload
     * @param  array<string, mixed>  $response
     */
    public function store(
        User $user,
        string $operationId,
        array $fingerprintPayload,
        string $entityType,
        string $entityId,
        string $operation,
        int $responseCode,
        array $response,
    ): IdempotencyKey {
        try {
            return IdempotencyKey::create([
                'user_id' => $user->id,
                'key_hash' => $this->hashOperation($user, $operationId),
                'request_hash' => $this->fingerprint($fingerprintPayload),
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'operation' => $operation,
                'response_code' => $responseCode,
                'response_json' => $response,
            ]);
        } catch (QueryException $exception) {
            // Another request with the same operation id committed first.
            return $this->find($user, $operationId) ?? throw $exception;
        }
    }
}
