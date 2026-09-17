<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Api\V1\Concerns\ApiV1Helpers;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use ApiV1Helpers;
    use RefreshDatabase;

    public function test_profile_can_be_viewed(): void
    {
        $data = $this->register()->json('data');

        $this->authRequest($data['access_token'], 'GET', '/api/v1/user')
            ->assertOk()
            ->assertJsonPath('data.email', 'test@example.com');
    }

    public function test_profile_can_be_updated(): void
    {
        $data = $this->register()->json('data');

        $this->authRequest($data['access_token'], 'PATCH', '/api/v1/user', [
            'name' => 'Updated Name',
            'email' => 'updated@example.com',
        ])->assertOk()->assertJsonPath('data.name', 'Updated Name');

        $this->assertDatabaseHas('users', ['email' => 'updated@example.com']);
    }

    public function test_profile_update_rejects_a_duplicate_email(): void
    {
        $this->makeUser(['email' => 'other@example.com']);
        $data = $this->register()->json('data');

        $this->authRequest($data['access_token'], 'PATCH', '/api/v1/user', [
            'name' => 'Test',
            'email' => 'other@example.com',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_password_can_be_changed_and_other_sessions_are_revoked(): void
    {
        $first = $this->register()->json('data');
        $second = $this->login()->json('data');

        $this->authRequest($second['access_token'], 'PUT', '/api/v1/user/password', [
            'current_password' => 'Password123!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertOk();

        // The session that performed the change stays authenticated.
        $this->authRequest($second['access_token'], 'GET', '/api/v1/user')->assertOk();

        $this->forgetAuthGuard();

        // Every other session loses its access tokens.
        $this->authRequest($first['access_token'], 'GET', '/api/v1/user')->assertStatus(401);

        $this->assertTrue(Hash::check('NewPassword123!', User::first()->password));
    }

    public function test_password_change_requires_the_correct_current_password(): void
    {
        $data = $this->register()->json('data');

        $this->authRequest($data['access_token'], 'PUT', '/api/v1/user/password', [
            'current_password' => 'WrongPassword1!',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');
    }

    public function test_account_can_be_deleted_with_the_current_password(): void
    {
        $data = $this->register()->json('data');

        $this->authRequest($data['access_token'], 'DELETE', '/api/v1/user', [
            'password' => 'Password123!',
        ])->assertOk();

        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }

    public function test_account_deletion_requires_the_correct_password(): void
    {
        $data = $this->register()->json('data');

        $this->authRequest($data['access_token'], 'DELETE', '/api/v1/user', [
            'password' => 'WrongPassword1!',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
    }
}
