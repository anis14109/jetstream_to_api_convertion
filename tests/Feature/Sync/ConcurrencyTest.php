<?php

namespace Tests\Feature\Sync;

use App\Models\SyncCursor;
use App\Models\User;
use App\Services\Api\V1\ChangeTracker;
use App\Services\Api\V1\IdempotencyService;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;
use Throwable;

/**
 * Runs genuinely parallel operations in separate OS processes against a shared
 * file-backed SQLite database. The in-memory `:memory:` database used by the
 * rest of the suite cannot be shared across processes, so this test owns its
 * own database file and cleans it up afterwards.
 *
 * If the environment cannot spawn child processes the test skips rather than
 * producing a false negative.
 */
class ConcurrencyTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('The pdo_sqlite extension is required for the multi-process concurrency test.');
        }

        $this->databasePath = storage_path('framework/testing/concurrency-'.Str::random(10).'.sqlite');

        File::ensureDirectoryExists(dirname($this->databasePath));
        File::delete($this->databasePath);
        File::put($this->databasePath, '');

        $this->useFileDatabase();

        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        config([
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.busy_timeout' => null,
            'database.connections.sqlite.journal_mode' => null,
        ]);

        DB::purge('sqlite');

        if (isset($this->databasePath)) {
            File::delete($this->databasePath);
        }

        parent::tearDown();
    }

    public function test_concurrent_requests_sharing_an_operation_id_reserve_it_exactly_once(): void
    {
        $user = User::factory()->create();
        $service = app(IdempotencyService::class);

        $operationId = 'op-'.Str::uuid();
        $entityId = (string) Str::ulid();

        $fingerprint = $service->fingerprint([
            'operation' => 'create',
            'entity_type' => 'student',
            'entity_id' => $entityId,
            'data' => ['name' => 'Concurrent Student'],
        ]);

        $outputs = $this->runWorkerGroup(array_fill(0, 8, [
            'WORKER_MODE' => 'idempotency',
            'WORKER_OP_ID' => $operationId,
            'WORKER_FINGERPRINT' => $fingerprint,
            'WORKER_ENTITY_ID' => $entityId,
        ]));

        $statuses = array_map($this->extractStatus(...), $outputs);
        $summary = 'Statuses: '.implode(', ', $statuses);

        $this->assertSame(1, count(array_keys($statuses, 'new')), 'Exactly one worker may win the reservation. '.$summary);
        $this->assertSame(0, count(array_keys($statuses, 'conflict')), 'No worker may report a conflict for an identical payload. '.$summary);
        $this->assertSame(
            count($statuses) - 1,
            count(array_keys($statuses, 'pending')),
            'Every other worker must observe the in-flight reservation. '.$summary,
        );

        $keyHash = $service->hashOperation($user, $operationId);

        $this->assertSame(
            1,
            DB::table('idempotency_keys')->where('key_hash', $keyHash)->count(),
            'The unique index must allow exactly one reservation row.',
        );

        $this->assertNull(
            DB::table('idempotency_keys')->where('key_hash', $keyHash)->value('completed_at'),
            'The reservation must still be pending because no worker completed the operation.',
        );
    }

    public function test_concurrent_acks_never_move_the_cursor_backward(): void
    {
        $user = User::factory()->create();

        SyncCursor::create([
            'user_id' => $user->id,
            'client_id' => 'device-concurrent',
            'last_pulled_cursor' => 100,
            'acknowledged_cursor' => 0,
        ]);

        $values = [90, 40, 10, 70, 30, 50, 20, 80];

        $environments = array_map(fn (int $value): array => [
            'WORKER_MODE' => 'cursor',
            'WORKER_CLIENT' => 'device-concurrent',
            'WORKER_CURSOR' => (string) $value,
        ], $values);

        $this->runWorkerGroup($environments);

        $cursor = SyncCursor::query()
            ->where('user_id', $user->id)
            ->where('client_id', 'device-concurrent')
            ->firstOrFail();

        $this->assertSame(
            max($values),
            $cursor->acknowledged_cursor,
            'The acknowledged cursor must converge on the highest ACK, regardless of completion order.',
        );

        $this->assertSame(100, $cursor->last_pulled_cursor, 'ACKing must not alter the delivery cursor.');
    }

    public function test_a_late_concurrent_ack_cannot_regress_a_completed_cursor(): void
    {
        $user = User::factory()->create();

        SyncCursor::create([
            'user_id' => $user->id,
            'client_id' => 'device-late',
            'last_pulled_cursor' => 100,
            'acknowledged_cursor' => 60,
        ]);

        // One worker acknowledges a lower value than the already-applied
        // checkpoint while another acknowledges a higher one.
        $environments = [
            [
                'WORKER_MODE' => 'cursor',
                'WORKER_CLIENT' => 'device-late',
                'WORKER_CURSOR' => '30',
            ],
            [
                'WORKER_MODE' => 'cursor',
                'WORKER_CLIENT' => 'device-late',
                'WORKER_CURSOR' => '75',
            ],
        ];

        $this->runWorkerGroup($environments);

        $cursor = SyncCursor::query()
            ->where('user_id', $user->id)
            ->where('client_id', 'device-late')
            ->firstOrFail();

        $this->assertSame(75, $cursor->acknowledged_cursor);
    }

    public function test_concurrent_pulls_never_move_the_delivered_cursor_backward(): void
    {
        $user = User::factory()->create();
        $tracker = app(ChangeTracker::class);

        for ($i = 0; $i < 100; $i++) {
            $tracker->record($user, 'student', 'student-'.$i, 'created', ['id' => 'student-'.$i]);
        }

        // Each worker starts from a different checkpoint and reads a short page,
        // so the revisions they deliver (10, 60 and 100) are wildly out of order.
        $environments = array_map(fn (int $start): array => [
            'WORKER_MODE' => 'pull',
            'WORKER_CLIENT' => 'device-pull',
            'WORKER_CURSOR' => (string) $start,
            'WORKER_LIMIT' => '10',
        ], [0, 50, 90]);

        $this->runWorkerGroup($environments);

        $cursor = SyncCursor::query()
            ->where('user_id', $user->id)
            ->where('client_id', 'device-pull')
            ->firstOrFail();

        $this->assertSame(
            100,
            $cursor->last_pulled_cursor,
            'The delivered cursor must converge on the highest revision, regardless of pull order.',
        );

        $this->assertSame(0, $cursor->acknowledged_cursor, 'Pulling must never acknowledge changes.');
    }

    private function useFileDatabase(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
            'database.connections.sqlite.busy_timeout' => 5000,
            'database.connections.sqlite.journal_mode' => 'WAL',
        ]);

        DB::purge('sqlite');
    }

    /**
     * @param  array<int, array<string, string>>  $environments
     * @return array<int, string>
     */
    private function runWorkerGroup(array $environments): array
    {
        $barrierDirectory = storage_path('framework/testing/barrier-'.Str::random(10));

        File::ensureDirectoryExists($barrierDirectory);

        $processes = [];

        try {
            foreach ($environments as $environment) {
                $processes[] = $this->startWorker(array_merge($environment, [
                    'WORKER_BARRIER_DIR' => $barrierDirectory,
                    'WORKER_BARRIER_COUNT' => (string) count($environments),
                ]));
            }
        } catch (Throwable $exception) {
            File::deleteDirectory($barrierDirectory);

            $this->markTestSkipped('Unable to start worker processes: '.$exception->getMessage());
        }

        try {
            return $this->collectResults($processes);
        } finally {
            File::deleteDirectory($barrierDirectory);
        }
    }

    /**
     * @param  array<string, string>  $environment
     */
    private function startWorker(array $environment): InvokedProcess
    {
        return Process::path(base_path())
            ->timeout(60)
            ->env(array_merge([
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $this->databasePath,
                'WORKER_DB' => $this->databasePath,
            ], $environment))
            ->start(['php', base_path('tests/Support/concurrency-worker.php')]);
    }

    /**
     * @param  array<int, InvokedProcess>  $processes
     * @return array<int, string>
     */
    private function collectResults(array $processes): array
    {
        $outputs = [];

        foreach ($processes as $index => $process) {
            $result = $process->wait();

            $this->assertTrue(
                $result->successful(),
                sprintf('Worker #%d failed: %s%s', $index, $result->errorOutput(), $result->output()),
            );

            $outputs[] = $result->output();
        }

        return $outputs;
    }

    private function extractStatus(string $output): string
    {
        if (preg_match('/RESULT:([a-z_]+)/', $output, $matches) !== 1) {
            return 'unknown';
        }

        return $matches[1];
    }
}
