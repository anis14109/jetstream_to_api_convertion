<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\Feature\Api\V1\Concerns\ApiV1Helpers;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use ApiV1Helpers;
    use RefreshDatabase;

    private function confirmPassword(string $token): void
    {
        $this->authRequest($token, 'POST', '/api/v1/user/confirm-password', [
            'password' => 'Password123!',
        ])->assertOk();
    }

    private function enableAndConfirm(string $token): string
    {
        $this->confirmPassword($token);

        $secret = $this->authRequest($token, 'POST', '/api/v1/two-factor/enable')
            ->assertOk()
            ->json('data.secret');

        $code = (new Google2FA)->getCurrentOtp($secret);

        $this->authRequest($token, 'POST', '/api/v1/two-factor/confirm', ['code' => $code])
            ->assertOk();

        return $secret;
    }

    public function test_enabling_two_factor_requires_password_confirmation(): void
    {
        $data = $this->register()->json('data');

        $this->authRequest($data['access_token'], 'POST', '/api/v1/two-factor/enable')
            ->assertStatus(423)
            ->assertJsonPath('code', 'PASSWORD_CONFIRMATION_REQUIRED');
    }

    public function test_two_factor_can_be_enabled_and_confirmed(): void
    {
        $data = $this->register()->json('data');
        $token = $data['access_token'];

        $this->confirmPassword($token);

        $enable = $this->authRequest($token, 'POST', '/api/v1/two-factor/enable');
        $enable->assertOk()->assertJsonStructure(['data' => ['secret', 'recovery_codes', 'qr_code']]);
        $this->assertCount(8, $enable->json('data.recovery_codes'));

        $code = (new Google2FA)->getCurrentOtp($enable->json('data.secret'));

        $this->authRequest($token, 'POST', '/api/v1/two-factor/confirm', ['code' => $code])
            ->assertOk();

        $this->assertNotNull(User::first()->two_factor_confirmed_at);
    }

    public function test_confirmation_rejects_an_invalid_code(): void
    {
        $data = $this->register()->json('data');
        $token = $data['access_token'];

        $this->confirmPassword($token);
        $this->authRequest($token, 'POST', '/api/v1/two-factor/enable')->assertOk();

        $this->authRequest($token, 'POST', '/api/v1/two-factor/confirm', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    public function test_recovery_codes_can_be_regenerated(): void
    {
        $data = $this->register()->json('data');
        $token = $data['access_token'];

        $this->enableAndConfirm($token);

        $response = $this->authRequest($token, 'GET', '/api/v1/two-factor/recovery-codes');
        $response->assertOk()->assertJsonStructure(['data' => ['recovery_codes']]);
        $this->assertCount(8, $response->json('data.recovery_codes'));
    }

    public function test_two_factor_can_be_disabled(): void
    {
        $data = $this->register()->json('data');
        $token = $data['access_token'];

        $this->enableAndConfirm($token);

        $this->authRequest($token, 'DELETE', '/api/v1/two-factor')->assertOk();

        $user = User::first();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_login_returns_a_challenge_when_two_factor_is_active(): void
    {
        $data = $this->register()->json('data');
        $token = $data['access_token'];

        $secret = $this->enableAndConfirm($token);

        $this->authRequest($token, 'POST', '/api/v1/auth/logout')->assertOk();
        $this->forgetAuthGuard();

        $challenge = $this->login();
        $challenge->assertOk()->assertJsonPath('data.two_factor_required', true);

        $code = (new Google2FA)->getCurrentOtp($secret);

        $verified = $this->postJson('/api/v1/auth/two-factor-challenge', [
            'two_factor_token' => $challenge->json('data.two_factor_token'),
            'code' => $code,
            'device_name' => 'phpunit',
        ]);

        $verified->assertOk()->assertJsonStructure(['data' => ['access_token', 'refresh_token']]);

        $this->forgetAuthGuard();

        $this->authRequest($verified->json('data.access_token'), 'GET', '/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'test@example.com');
    }

    public function test_challenge_rejects_an_invalid_code(): void
    {
        $data = $this->register()->json('data');
        $token = $data['access_token'];

        $this->enableAndConfirm($token);
        $this->authRequest($token, 'POST', '/api/v1/auth/logout')->assertOk();
        $this->forgetAuthGuard();

        $challenge = $this->login()->json('data');

        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'two_factor_token' => $challenge['two_factor_token'],
            'code' => '000000',
        ])->assertStatus(422)->assertJsonValidationErrors('code');
    }
}
