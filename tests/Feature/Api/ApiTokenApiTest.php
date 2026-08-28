<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiTokenApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(): array
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        return compact('user', 'token');
    }

    // -------------------------------------------------------
    // List Tokens
    // -------------------------------------------------------

    public function test_user_can_list_tokens(): void
    {
        ['user' => $user, 'token' => $token] = $this->actingAsUser();

        $user->createToken('Token One', ['read']);
        $user->createToken('Token Two', ['read', 'write']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/api-tokens');

        $response->assertOk();
        $this->assertCount(3, $response->json()); // 1 created in actingAsUser + 2 here
    }

    public function test_unauthenticated_user_cannot_list_tokens(): void
    {
        $response = $this->getJson('/api/api-tokens');

        $response->assertStatus(401);
    }

    // -------------------------------------------------------
    // Create Token
    // -------------------------------------------------------

    public function test_user_can_create_token(): void
    {
        ['token' => $token] = $this->actingAsUser();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/api-tokens', [
                'name' => 'My New Token',
                'permissions' => ['read', 'create'],
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'token' => ['id', 'name', 'abilities', 'created_at'],
                'plain_text_token',
            ]);

        $this->assertEquals('My New Token', $response->json('token.name'));
        $this->assertContains('read', $response->json('token.abilities'));
        $this->assertContains('create', $response->json('token.abilities'));
        $this->assertNotContains('delete', $response->json('token.abilities'));
    }

    public function test_token_creation_requires_name(): void
    {
        ['token' => $token] = $this->actingAsUser();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/api-tokens', [
                'permissions' => ['read'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_token_creation_requires_permissions(): void
    {
        ['token' => $token] = $this->actingAsUser();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/api-tokens', [
                'name' => 'Test',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('permissions');
    }

    public function test_token_creation_validates_permission_values(): void
    {
        ['token' => $token] = $this->actingAsUser();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/api-tokens', [
                'name' => 'Test',
                'permissions' => ['invalid-permission'],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('permissions.0');
    }

    public function test_unauthenticated_user_cannot_create_token(): void
    {
        $response = $this->postJson('/api/api-tokens', [
            'name' => 'Test',
            'permissions' => ['read'],
        ]);

        $response->assertStatus(401);
    }

    // -------------------------------------------------------
    // Update Token Permissions
    // -------------------------------------------------------

    public function test_user_can_update_token_permissions(): void
    {
        ['user' => $user, 'token' => $token] = $this->actingAsUser();

        $apiToken = $user->tokens()->create([
            'name' => 'Test Token',
            'token' => Str::random(40),
            'abilities' => ['read'],
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/api-tokens/{$apiToken->id}", [
                'permissions' => ['read', 'delete'],
            ]);

        $response->assertOk()
            ->assertJsonFragment(['abilities' => ['read', 'delete']]);
    }

    public function test_updating_nonexistent_token_returns_404(): void
    {
        ['token' => $token] = $this->actingAsUser();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/api-tokens/99999', [
                'permissions' => ['read'],
            ]);

        $response->assertStatus(404);
    }

    // -------------------------------------------------------
    // Delete Token
    // -------------------------------------------------------

    public function test_user_can_delete_token(): void
    {
        ['user' => $user, 'token' => $token] = $this->actingAsUser();

        $apiToken = $user->tokens()->create([
            'name' => 'To Delete',
            'token' => Str::random(40),
            'abilities' => ['read'],
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/api-tokens/{$apiToken->id}");

        $response->assertOk()
            ->assertJson(['message' => 'Token deleted.']);

        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $apiToken->id]);
    }

    public function test_deleting_nonexistent_token_returns_404(): void
    {
        ['token' => $token] = $this->actingAsUser();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson('/api/api-tokens/99999');

        $response->assertStatus(404);
    }
}
