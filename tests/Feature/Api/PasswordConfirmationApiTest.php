<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordConfirmationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_confirm_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/user/confirm-password', ['password' => 'password']);

        $response->assertOk()
            ->assertJson(['message' => 'Password confirmed.']);
    }

    public function test_password_confirmation_requires_correct_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password')]);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/user/confirm-password', ['password' => 'wrong-password']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_password_confirmation_requires_password_field(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/user/confirm-password', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_unauthenticated_user_cannot_confirm_password(): void
    {
        $response = $this->postJson('/api/user/confirm-password', [
            'password' => 'password',
        ]);

        $response->assertStatus(401);
    }
}
