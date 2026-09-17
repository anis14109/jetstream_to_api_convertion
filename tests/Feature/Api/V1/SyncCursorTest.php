<?php

namespace Tests\Feature\Api\V1;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Api\V1\ChangeTracker;
use App\Sync\SyncEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Cursor checkpoints must be strictly monotonic. Whatever order ACKs and pulls
 * arrive in, neither `acknowledged_cursor` nor `last_pulled_cursor` may ever
 * move backwards.
 */
class SyncCursorTest extends TestCase
{
    use RefreshDatabase;

    private SyncEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(SyncEngine::class);
    }

    private function userWithChanges(int $count): User
    {
        $user = User::factory()->create();
        $tracker = app(ChangeTracker::class);

        for ($i = 0; $i < $count; $i++) {
            $tracker->record($user, 'student', (string) Str::ulid(), 'created', ['id' => (string) Str::ulid()]);
        }

        return $user;
    }

    public function test_ack_advances_the_checkpoint_forward(): void
    {
        $user = $this->userWithChanges(3);

        $this->engine->pull($user, 'device-a', null, 100);
        $result = $this->engine->ack($user, 'device-a', 3);

        $this->assertSame(3, $result['acknowledged_cursor']);
        $this->assertDatabaseHas('sync_cursors', [
            'user_id' => $user->id,
            'client_id' => 'device-a',
            'acknowledged_cursor' => 3,
        ]);
    }

    public function test_ack_cannot_move_the_checkpoint_backward(): void
    {
        $user = $this->userWithChanges(3);

        $this->engine->pull($user, 'device-a', null, 100);
        $this->engine->ack($user, 'device-a', 3);

        $result = $this->engine->ack($user, 'device-a', 1);

        $this->assertSame(3, $result['acknowledged_cursor']);
        $this->assertDatabaseHas('sync_cursors', [
            'user_id' => $user->id,
            'client_id' => 'device-a',
            'acknowledged_cursor' => 3,
        ]);
    }

    public function test_out_of_order_acks_converge_on_the_highest_value(): void
    {
        $user = $this->userWithChanges(5);

        $this->engine->pull($user, 'device-a', null, 100);

        // Simulate two concurrent ACKs arriving in the "wrong" order: the
        // smaller one lands last but must not win.
        $this->engine->ack($user, 'device-a', 5);
        $this->engine->ack($user, 'device-a', 2);
        $this->engine->ack($user, 'device-a', 4);

        $cursor = $this->engine->currentCursor($user, 'device-a');
        $this->assertSame(5, $cursor['acknowledged_cursor']);
    }

    public function test_a_duplicate_ack_is_a_noop(): void
    {
        $user = $this->userWithChanges(2);

        $this->engine->pull($user, 'device-a', null, 100);
        $first = $this->engine->ack($user, 'device-a', 2);
        $second = $this->engine->ack($user, 'device-a', 2);

        $this->assertSame($first['acknowledged_cursor'], $second['acknowledged_cursor']);
        $this->assertDatabaseCount('sync_cursors', 1);
    }

    public function test_a_future_ack_is_rejected(): void
    {
        $user = $this->userWithChanges(3);

        $this->engine->pull($user, 'device-a', null, 100);

        $this->expectException(ApiException::class);

        $this->engine->ack($user, 'device-a', 4);
    }

    public function test_a_negative_ack_is_rejected(): void
    {
        $user = $this->userWithChanges(1);

        $this->expectException(ApiException::class);

        $this->engine->ack($user, 'device-a', -1);
    }

    public function test_ack_beyond_the_delivered_cursor_is_rejected(): void
    {
        $user = $this->userWithChanges(3);

        // Nothing was pulled to this client yet, so it may not acknowledge.
        $this->expectException(ApiException::class);

        $this->engine->ack($user, 'device-a', 3);
    }

    public function test_pull_cannot_move_the_delivered_checkpoint_backward(): void
    {
        $user = $this->userWithChanges(5);

        $this->engine->pull($user, 'device-a', null, 100);
        $before = $this->engine->currentCursor($user, 'device-a');
        $this->assertSame(5, $before['last_pulled_cursor']);

        // Re-requesting from the beginning delivers only the first item; the
        // delivered checkpoint must stay at 5.
        $this->engine->pull($user, 'device-a', 0, 1);

        $after = $this->engine->currentCursor($user, 'device-a');
        $this->assertSame(5, $after['last_pulled_cursor']);
        $this->assertSame(0, $after['acknowledged_cursor']);
    }

    public function test_cursors_are_isolated_per_client(): void
    {
        $user = $this->userWithChanges(4);

        $this->engine->pull($user, 'device-a', null, 100);
        $this->engine->ack($user, 'device-a', 4);

        $this->engine->pull($user, 'device-b', null, 100);
        $this->engine->ack($user, 'device-b', 2);

        $this->assertSame(4, $this->engine->currentCursor($user, 'device-a')['acknowledged_cursor']);
        $this->assertSame(2, $this->engine->currentCursor($user, 'device-b')['acknowledged_cursor']);
    }

    public function test_the_legacy_cursor_alias_matches_the_acknowledged_checkpoint(): void
    {
        $user = $this->userWithChanges(2);

        $this->engine->pull($user, 'device-a', null, 100);
        $this->engine->ack($user, 'device-a', 2);

        $cursor = $this->engine->currentCursor($user, 'device-a');
        $this->assertSame($cursor['acknowledged_cursor'], $cursor['cursor']);
    }
}
