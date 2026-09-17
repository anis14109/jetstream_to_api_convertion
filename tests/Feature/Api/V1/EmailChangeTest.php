<?php

namespace Tests\Feature\Api\V1;

use App\Notifications\VerifyEmailApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Api\V1\Concerns\ApiV1Helpers;
use Tests\TestCase;

/**
 * Changing the account email must invalidate the previous verification and
 * start a fresh verification cycle for the new address.
 */
class EmailChangeTest extends TestCase
{
    use ApiV1Helpers;
    use RefreshDatabase;

    private function signedUrlFor(string $email, int $userId): string
    {
        return URL::temporarySignedRoute(
            'api.v1.auth.email.verify',
            now()->addMinutes(60),
            ['id' => $userId, 'hash' => sha1($email)],
        );
    }

    public function test_changing_a_verified_email_clears_verification(): void
    {
        Notification::fake();

        $user = $this->makeUser(['email' => 'old@example.com', 'email_verified_at' => now()]);
        $token = $this->login('old@example.com')->json('data.access_token');

        $this->authRequest($token, 'PATCH', '/api/v1/user', [
            'name' => $user->name,
            'email' => 'new@example.com',
        ])->assertOk()
            ->assertJsonPath('data.email', 'new@example.com')
            ->assertJsonPath('data.email_verified_at', null);

        $user->refresh();
        $this->assertSame('new@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_changing_the_email_sends_a_verification_email_to_the_new_address(): void
    {
        Notification::fake();

        $user = $this->makeUser(['email' => 'old@example.com', 'email_verified_at' => now()]);
        $token = $this->login('old@example.com')->json('data.access_token');

        $this->authRequest($token, 'PATCH', '/api/v1/user', [
            'name' => $user->name,
            'email' => 'new@example.com',
        ])->assertOk();

        Notification::assertSentTo($user, VerifyEmailApi::class);
    }

    public function test_the_old_verification_link_cannot_verify_the_new_email(): void
    {
        Notification::fake();

        $user = $this->makeUser(['email' => 'old@example.com', 'email_verified_at' => now()]);
        $oldUrl = $this->signedUrlFor('old@example.com', $user->id);

        $token = $this->login('old@example.com')->json('data.access_token');

        $this->authRequest($token, 'PATCH', '/api/v1/user', [
            'name' => $user->name,
            'email' => 'new@example.com',
        ])->assertOk();

        // The link still carries the hash of the old address, so it must fail.
        $this->getJson($oldUrl)->assertStatus(403);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_the_new_verification_link_works(): void
    {
        Notification::fake();

        $user = $this->makeUser(['email' => 'old@example.com', 'email_verified_at' => now()]);
        $token = $this->login('old@example.com')->json('data.access_token');

        $this->authRequest($token, 'PATCH', '/api/v1/user', [
            'name' => $user->name,
            'email' => 'new@example.com',
        ])->assertOk();

        $this->getJson($this->signedUrlFor('new@example.com', $user->id))
            ->assertOk()
            ->assertJsonPath('data.verified', true);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_saving_the_same_email_does_not_reset_verification_or_notify(): void
    {
        Notification::fake();

        $user = $this->makeUser(['email' => 'same@example.com', 'email_verified_at' => now()]);
        $verifiedAt = $user->email_verified_at;
        $token = $this->login('same@example.com')->json('data.access_token');

        $this->authRequest($token, 'PATCH', '/api/v1/user', [
            'name' => 'Renamed Only',
            'email' => 'same@example.com',
        ])->assertOk();

        Notification::assertNothingSent();

        $user->refresh();
        $this->assertSame('Renamed Only', $user->name);
        $this->assertEquals($verifiedAt, $user->email_verified_at);
    }

    public function test_an_unverified_user_cannot_take_another_accounts_email(): void
    {
        Notification::fake();

        $this->makeUser(['email' => 'taken@example.com']);
        $data = $this->register()->json('data');

        $this->authRequest($data['access_token'], 'PATCH', '/api/v1/user', [
            'name' => 'Test User',
            'email' => 'taken@example.com',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_an_unverified_user_can_resend_after_changing_email(): void
    {
        Notification::fake();

        $user = $this->makeUser(['email' => 'old@example.com']);
        $token = $this->login('old@example.com')->json('data.access_token');

        $this->authRequest($token, 'PATCH', '/api/v1/user', [
            'name' => $user->name,
            'email' => 'new@example.com',
        ])->assertOk();

        $user->refresh();

        Notification::fake();

        $this->authRequest($token, 'POST', '/api/v1/auth/email/verification-notification')
            ->assertOk()
            ->assertJsonPath('data.verified', false);

        Notification::assertSentTo($user, VerifyEmailApi::class);

        $this->assertDatabaseMissing('users', ['email' => 'old@example.com']);
    }
}
