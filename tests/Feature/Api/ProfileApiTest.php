<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): string
    {
        $user = User::factory()->create();

        return $user->createToken('test-token')->plainTextToken;
    }

    // -------------------------------------------------------
    // Update Profile Information
    // -------------------------------------------------------

    public function test_user_can_update_profile_information(): void
    {
        $token = $this->actingAsUser();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/user/profile-information', [
                'name' => 'Updated Name',
                'email' => 'updated@example.com',
            ]);

        $response->assertOk()
            ->assertJsonFragment(['name' => 'Updated Name', 'email' => 'updated@example.com']);
    }

    public function test_profile_update_requires_name(): void
    {
        $token = $this->actingAsUser();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/user/profile-information', [
                'email' => 'updated@example.com',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_profile_update_requires_valid_email(): void
    {
        $token = $this->actingAsUser();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/user/profile-information', [
                'name' => 'Test',
                'email' => 'not-an-email',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_profile_update_requires_unique_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $token = $this->actingAsUser();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/user/profile-information', [
                'name' => 'Test',
                'email' => 'taken@example.com',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    public function test_unauthenticated_user_cannot_update_profile(): void
    {
        $response = $this->putJson('/api/user/profile-information', [
            'name' => 'Test',
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(401);
    }

    // -------------------------------------------------------
    // Update Password
    // -------------------------------------------------------

    public function test_user_can_update_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/user/password', [
                'current_password' => 'Password123!',
                'password' => 'NewPassword456!',
                'password_confirmation' => 'NewPassword456!',
            ]);

        $response->assertOk()
            ->assertJson(['message' => 'Password updated.']);

        $this->assertTrue(Hash::check('NewPassword456!', $user->fresh()->password));
    }

    public function test_password_update_requires_current_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/user/password', [
                'current_password' => 'wrong-password',
                'password' => 'NewPassword456!',
                'password_confirmation' => 'NewPassword456!',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('current_password');
    }

    public function test_password_update_requires_strong_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/user/password', [
                'current_password' => 'Password123!',
                'password' => '123',
                'password_confirmation' => '123',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_password_update_requires_confirmation(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/user/password', [
                'current_password' => 'Password123!',
                'password' => 'NewPassword456!',
                'password_confirmation' => 'different',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    // -------------------------------------------------------
    // Delete Account
    // -------------------------------------------------------

    public function test_user_can_delete_account(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/user', ['password' => 'Password123!']);

        $response->assertOk()
            ->assertJson(['message' => 'Account deleted.']);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_account_deletion_requires_correct_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/user', ['password' => 'wrong-password']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertNotNull($user->fresh());
    }

    public function test_unauthenticated_user_cannot_delete_account(): void
    {
        $response = $this->deleteJson('/api/user', ['password' => 'password']);

        $response->assertStatus(401);
    }
}
