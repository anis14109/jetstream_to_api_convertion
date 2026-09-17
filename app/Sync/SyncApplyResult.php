<?php

namespace App\Sync;

/**
 * The outcome of applying a single synchronization operation.
 *
 * `version` is the per-resource optimistic-concurrency counter; `revision` is
 * the global change-journal id (0 when the resolution did not append a journal
 * entry, e.g. server-wins). `resolution` is set when the operation was not
 * applied directly but settled through a conflict policy.
 */
final readonly class SyncApplyResult
{
    public function __construct(
        public int $version,
        public int $revision,
        public ?ConflictPolicy $resolution = null,
    ) {}
}
