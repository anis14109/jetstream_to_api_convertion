<?php

namespace Tests\Feature\Api\V1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\V1\Concerns\ApiV1Helpers;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use ApiV1Helpers;
    use RefreshDatabase;

    public function test_confirmation_requires_authentication(): void
    {
        $this->postJson('/api/v1/user/confirm-password', ['password' => 'Password123!'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_an_incorrect_password_is_rejected(): void
    {
        $data = $this->register()->json('data');

        $this->authRequest($data['access_token'], 'POST', '/api/v1/user/confirm-password', [
            'password' => 'WrongPassword1!',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_a_correct_password_confirms_and_unlocks_protected_actions(): void
    {
        $data = $this->register()->json('data');
        $token = $data['access_token'];

        $this->authRequest($token, 'POST', '/api/v1/two-factor/enable')
            ->assertStatus(423)
            ->assertJsonPath('code', 'PASSWORD_CONFIRMATION_REQUIRED');

        $this->authRequest($token, 'POST', '/api/v1/user/confirm-password', [
            'password' => 'Password123!',
        ])->assertOk();

        $this->authRequest($token, 'POST', '/api/v1/two-factor/enable')
            ->assertOk()
            ->assertJsonStructure(['data' => ['secret', 'recovery_codes', 'qr_code']]);
    }
}
