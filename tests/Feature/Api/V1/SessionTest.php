<?php

namespace Tests\Feature\Api\V1;

use App\Models\AuthSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\V1\Concerns\ApiV1Helpers;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use ApiV1Helpers;
    use RefreshDatabase;

    public function test_sessions_can_be_listed(): void
    {
        $first = $this->register()->json('data');
        $this->login()->json('data');

        $response = $this->authRequest($first['access_token'], 'GET', '/api/v1/sessions');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));

        $current = collect($response->json('data'))->where('current', true);
        $this->assertCount(1, $current);
        $this->assertSame($first['session']['id'], $current->first()['id']);
    }

    public function test_another_session_can_be_revoked_without_affecting_the_current_one(): void
    {
        $first = $this->register()->json('data');
        $second = $this->login()->json('data');

        $sessions = $this->authRequest($second['access_token'], 'GET', '/api/v1/sessions')->json('data');
        $other = collect($sessions)->firstWhere('current', false);

        $this->authRequest($second['access_token'], 'DELETE', '/api/v1/sessions/'.$other['id'])
            ->assertOk();

        $this->assertNotNull(AuthSession::query()->find($other['id'])->revoked_at);
        $this->authRequest($second['access_token'], 'GET', '/api/v1/user')->assertOk();
    }

    public function test_a_user_cannot_revoke_another_users_session(): void
    {
        $victim = $this->register()->json('data');

        $this->forgetAuthGuard();

        $attacker = $this->register(['email' => 'attacker@example.com'])->json('data');

        $this->authRequest($attacker['access_token'], 'DELETE', '/api/v1/sessions/'.$victim['session']['id'])
            ->assertStatus(404)
            ->assertJsonPath('code', 'NOT_FOUND');
    }
}
