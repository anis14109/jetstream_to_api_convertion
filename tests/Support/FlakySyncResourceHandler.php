<?php

namespace Tests\Support;

use App\Models\User;
use App\Sync\SyncApplyResult;
use RuntimeException;

/**
 * Wraps the in-memory handler and fails the first write on demand. Used to prove
 * that a failed operation does not leave a committed idempotency reservation
 * behind and can be retried deterministically with the same operation id.
 */
final class FlakySyncResourceHandler extends InMemorySyncResourceHandler
{
    public bool $failNext = true;

    public function create(User $user, string $entityId, array $data): SyncApplyResult
    {
        if ($this->failNext) {
            $this->failNext = false;

            throw new RuntimeException('Simulated business failure.');
        }

        return parent::create($user, $entityId, $data);
    }
}
