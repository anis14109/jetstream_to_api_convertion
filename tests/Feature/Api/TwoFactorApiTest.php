<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): array
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $token = $user->createToken('test-token')->plainTextToken;

        return compact('user', 'token');
    }

    // -------------------------------------------------------
    // Enable 2FA
    // -------------------------------------------------------

    public function test_user_can_enable_two_factor_auth(): void
    {
        ['user' => $user, 'token' => $token] = $this->actingAsUser();

        // Confirm password first
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/user/confirm-password', ['password' => 'password']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/two-factor-enable');

        $response->assertOk()
            ->assertJsonStructure(['secret', 'recovery_codes', 'qr_code']);

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNotNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_2fa_enable_requires_password_confirmation(): void
    {
        ['token' => $token] = $this->actingAsUser();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/two-factor-enable');

        $response->assertStatus(403)
            ->assertJson(['message' => 'Password confirmation required.']);
    }

    public function test_unauthenticated_user_cannot_enable_2fa(): void
    {
        $response = $this->postJson('/api/two-factor-enable');

        $response->assertStatus(401);
    }

    // -------------------------------------------------------
    // Confirm 2FA
    // -------------------------------------------------------

    public function test_user_can_confirm_two_factor_auth(): void
    {
        ['user' => $user, 'token' => $token] = $this->actingAsUser();

        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode(['CODE1', 'CODE2'])),
        ])->save();

        $code = $google2fa->GetCurrentOtp($secret);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/two-factor-confirm', ['code' => $code]);

        $response->assertOk()
            ->assertJson(['message' => 'Two-factor authentication confirmed.']);

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_2fa_confirm_requires_valid_code(): void
    {
        ['user' => $user, 'token' => $token] = $this->actingAsUser();

        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode(['CODE1'])),
        ])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/two-factor-confirm', ['code' => '000000']);

        $response->assertStatus(422)
            ->assertJson(['message' => 'The provided two-factor authentication code is invalid.']);
    }

    public function test_2fa_confirm_requires_code_field(): void
    {
        ['token' => $token] = $this->actingAsUser();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/two-factor-confirm', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('code');
    }

    // -------------------------------------------------------
    // Disable 2FA
    // -------------------------------------------------------

    public function test_user_can_disable_two_factor_auth(): void
    {
        ['user' => $user, 'token' => $token] = $this->actingAsUser();

        $google2fa = new Google2FA;
        $user->forceFill([
            'two_factor_secret' => encrypt($google2fa->generateSecretKey()),
            'two_factor_recovery_codes' => encrypt(json_encode(['CODE1'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/two-factor-disable');

        $response->assertOk()
            ->assertJson(['message' => 'Two-factor authentication disabled.']);

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    // -------------------------------------------------------
    // Recovery Codes
    // -------------------------------------------------------

    public function test_user_can_regenerate_recovery_codes(): void
    {
        ['user' => $user, 'token' => $token] = $this->actingAsUser();

        $google2fa = new Google2FA;
        $user->forceFill([
            'two_factor_secret' => encrypt($google2fa->generateSecretKey()),
            'two_factor_recovery_codes' => encrypt(json_encode(['OLD1', 'OLD2'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/two-factor-recovery-codes');

        $response->assertOk()
            ->assertJsonStructure(['recovery_codes']);

        $codes = $response->json('recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertNotContains('OLD1', $codes);
    }

    public function test_recovery_codes_require_2fa_to_be_enabled(): void
    {
        ['token' => $token] = $this->actingAsUser();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/two-factor-recovery-codes');

        $response->assertStatus(422)
            ->assertJson(['message' => 'Two-factor authentication has not been enabled.']);
    }
}
