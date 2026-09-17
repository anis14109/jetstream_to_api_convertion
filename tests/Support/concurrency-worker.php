<?php

/**
 * Standalone worker used by tests/Feature/Sync/ConcurrencyTest.php.
 *
 * It boots the application in a separate operating-system process so the test
 * can drive genuinely parallel operations against the same file-backed SQLite
 * database (the in-memory `:memory:` test database cannot be shared across
 * processes). It prints a single machine-readable `RESULT:` line.
 *
 * Environment:
 *   WORKER_DB           absolute path to the shared SQLite database
 *   WORKER_MODE         "idempotency" | "cursor"
 *   WORKER_OP_ID        operation id            (idempotency mode)
 *   WORKER_FINGERPRINT  payload fingerprint     (idempotency mode)
 *   WORKER_ENTITY_ID    entity id               (idempotency mode)
 *   WORKER_CLIENT       client/device id        (cursor mode)
 *   WORKER_CURSOR       cursor value to ACK     (cursor mode)
 *   WORKER_LIMIT        pull page size          (pull mode)
 *   WORKER_BARRIER_DIR  directory used to synchronise the start of every worker
 *   WORKER_BARRIER_COUNT number of workers that must reach the barrier
 */

use App\Models\User;
use App\Services\Api\V1\IdempotencyService;
use App\Sync\SyncEngine;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'database.default' => 'sqlite',
    'database.connections.sqlite.database' => getenv('WORKER_DB'),
    'database.connections.sqlite.busy_timeout' => 5000,
    'database.connections.sqlite.journal_mode' => 'WAL',
    'database.connections.sqlite.foreign_key_constraints' => true,
]);

DB::purge('sqlite');

/**
 * Wait until every sibling worker has booted, so the timed section below runs
 * in genuinely overlapping processes rather than in a staggered sequence.
 */
function awaitBarrier(): void
{
    $directory = getenv('WORKER_BARRIER_DIR');
    $count = (int) getenv('WORKER_BARRIER_COUNT');

    if ($directory === false || $directory === '' || $count < 1) {
        return;
    }

    touch($directory.DIRECTORY_SEPARATOR.getmypid().'.ready');

    $deadline = microtime(true) + 30;

    while (count(glob($directory.DIRECTORY_SEPARATOR.'*.ready')) < $count) {
        if (microtime(true) > $deadline) {
            fwrite(STDERR, 'ERROR:barrier-timeout'.PHP_EOL);

            exit(1);
        }

        usleep(2000);
    }
}

function main(): void
{
    $mode = getenv('WORKER_MODE') ?: 'idempotency';

    awaitBarrier();

    $user = User::query()->firstOrFail();

    if ($mode === 'cursor') {
        withLockRetry(fn () => app(SyncEngine::class)->ack(
            $user,
            (string) getenv('WORKER_CLIENT'),
            (int) getenv('WORKER_CURSOR'),
        ));

        fwrite(STDOUT, 'RESULT:ack:'.getenv('WORKER_CURSOR').PHP_EOL);

        return;
    }

    if ($mode === 'pull') {
        withLockRetry(fn () => app(SyncEngine::class)->pull(
            $user,
            (string) getenv('WORKER_CLIENT'),
            (int) getenv('WORKER_CURSOR'),
            (int) getenv('WORKER_LIMIT'),
        ));

        fwrite(STDOUT, 'RESULT:pull:'.getenv('WORKER_CURSOR').PHP_EOL);

        return;
    }

    $result = withLockRetry(fn () => app(IdempotencyService::class)->begin(
        $user,
        (string) getenv('WORKER_OP_ID'),
        (string) getenv('WORKER_FINGERPRINT'),
        'student',
        (string) getenv('WORKER_ENTITY_ID'),
        'create',
    ));

    fwrite(STDOUT, 'RESULT:'.$result['status'].PHP_EOL);
}

/**
 * SQLite has no row-level locks, and a deferred transaction that has already
 * read cannot upgrade to a writer without risking SQLITE_BUSY. Retrying is the
 * documented way to handle that contention. It is a property of the SQLite
 * test substrate only: production runs on MySQL/PostgreSQL, where
 * `lockForUpdate()` and the atomic `CASE` update serialise the writers.
 */
function withLockRetry(callable $callback): mixed
{
    $attempts = 0;

    while (true) {
        try {
            return $callback();
        } catch (Throwable $exception) {
            $locked = str_contains($exception->getMessage(), 'database is locked')
                || str_contains($exception->getMessage(), 'database table is locked');

            if (! $locked || ++$attempts >= 100) {
                throw $exception;
            }

            usleep(50_000);
        }
    }
}

try {
    main();
} catch (Throwable $exception) {
    fwrite(STDERR, 'ERROR:'.$exception::class.':'.$exception->getMessage().PHP_EOL);

    exit(1);
}
