<?php

namespace App\Services\Api\V1;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Profile, password and account lifecycle for API clients.
 */
class ProfileService
{
    public function __construct(private readonly RefreshTokenService $refreshTokens) {}

    /**
     * @param  array{name: string, email: string}  $data
     */
    public function updateProfile(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $email = Str::lower($data['email']);
            $emailChanged = $user->email !== $email;

            $attributes = [
                'name' => $data['name'],
                'email' => $email,
            ];

            if ($emailChanged && $user instanceof MustVerifyEmail) {
                $attributes['email_verified_at'] = null;
            }

            $user->forceFill($attributes)->save();

            if ($emailChanged && $user instanceof MustVerifyEmail) {
                $user->sendEmailVerificationNotification();
            }

            return $user->refresh();
        });
    }

    /**
     * Verify the current password, persist the new one and (optionally) revoke
     * every other device session.
     */
    public function updatePassword(
        User $user,
        string $currentPassword,
        string $newPassword,
        ?string $keepSessionId = null,
    ): void {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ApiException::validation(
                ['current_password' => ['The provided password does not match your current password.']],
                'The provided password does not match your current password.',
            );
        }

        DB::transaction(function () use ($user, $newPassword, $keepSessionId): void {
            $user->forceFill(['password' => $newPassword])->save();

            if (config('api.security.revoke_sessions_on_password_change')) {
                $this->refreshTokens->revokeAllForUser($user, $keepSessionId);
            }
        });
    }

    /**
     * Permanently delete the account and all of its sessions.
     */
    public function deleteAccount(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ApiException::validation(
                ['password' => ['The password is incorrect.']],
                'The password is incorrect.',
            );
        }

        DB::transaction(function () use ($user): void {
            $this->refreshTokens->revokeAllForUser($user);
            $user->delete();
        });
    }
}
