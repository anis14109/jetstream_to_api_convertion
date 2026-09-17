<?php

namespace App\Services\Api\V1;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;

/**
 * Two-factor authentication for API clients: enable, confirm, disable,
 * recovery codes and the login-time challenge. The challenge token is a
 * short-lived Sanctum token that only grants the `two-factor-challenge`
 * ability and cannot be used to reach any other endpoint.
 */
class TwoFactorService
{
    private const RECOVERY_CODE_COUNT = 8;

    /**
     * Generate a new secret and recovery codes. The user must still confirm
     * with a valid TOTP code before 2FA becomes active.
     *
     * @return array{secret: string, recovery_codes: array<int, string>, qr_code: string}
     */
    public function enable(User $user): array
    {
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();
        $recoveryCodes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
            'two_factor_confirmed_at' => null,
        ])->save();

        return [
            'secret' => $secret,
            'recovery_codes' => $recoveryCodes,
            'qr_code' => $google2fa->getQRCodeUrl(config('app.name'), $user->email, $secret),
        ];
    }

    /**
     * Confirm 2FA by verifying a TOTP code against the pending secret.
     */
    public function confirm(User $user, string $code): void
    {
        if (! $user->two_factor_secret) {
            throw ApiException::validation(
                ['code' => ['Two-factor authentication has not been enabled.']],
                'Two-factor authentication has not been enabled.',
            );
        }

        if (! $this->verify($user, $code)) {
            throw ApiException::validation(
                ['code' => ['The provided two-factor authentication code is invalid.']],
                'The provided two-factor authentication code is invalid.',
            );
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /**
     * @return array<int, string>
     */
    public function regenerateRecoveryCodes(User $user): array
    {
        if (! $user->two_factor_secret) {
            throw ApiException::validation(
                ['recovery_codes' => ['Two-factor authentication has not been enabled.']],
                'Two-factor authentication has not been enabled.',
            );
        }

        $codes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => encrypt(json_encode($codes)),
        ])->save();

        return $codes;
    }

    public function verify(User $user, string $code): bool
    {
        if (! $user->two_factor_secret) {
            return false;
        }

        return (bool) (new Google2FA)->verifyKey(decrypt($user->two_factor_secret), $code, 1);
    }

    /**
     * Verify and consume a single recovery code.
     */
    public function verifyRecoveryCode(User $user, string $recoveryCode): bool
    {
        if (! $user->two_factor_recovery_codes) {
            return false;
        }

        $codes = json_decode(decrypt($user->two_factor_recovery_codes), true) ?: [];
        $candidate = strtoupper(trim($recoveryCode));

        foreach ($codes as $index => $code) {
            if (hash_equals($code, $candidate)) {
                unset($codes[$index]);

                $user->forceFill([
                    'two_factor_recovery_codes' => encrypt(json_encode(array_values($codes))),
                ])->save();

                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the user owning a valid, unexpired two-factor challenge token.
     */
    public function resolveChallengeUser(string $plainTextToken): ?User
    {
        $token = PersonalAccessToken::findToken($plainTextToken);

        if (! $token || ! $token->can((string) config('api.tokens.two_factor_challenge_ability'))) {
            return null;
        }

        if ($token->expires_at && $token->expires_at->isPast()) {
            return null;
        }

        return $token->tokenable;
    }

    /**
     * Consume the one-time challenge token.
     */
    public function consumeChallengeToken(string $plainTextToken): void
    {
        PersonalAccessToken::findToken($plainTextToken)?->delete();
    }

    /**
     * Complete a login challenge, returning the verified user.
     */
    public function completeChallenge(string $plainTextToken, ?string $code, ?string $recoveryCode): User
    {
        $user = $this->resolveChallengeUser($plainTextToken);

        if (! $user) {
            throw ApiException::invalidToken('The two-factor challenge token is invalid or has expired.');
        }

        $verified = $recoveryCode !== null
            ? $this->verifyRecoveryCode($user, $recoveryCode)
            : $this->verify($user, (string) $code);

        if (! $verified) {
            throw ApiException::validation(
                $recoveryCode !== null
                    ? ['recovery_code' => ['The provided recovery code is invalid.']]
                    : ['code' => ['The provided two-factor authentication code is invalid.']],
                $recoveryCode !== null
                    ? 'The provided recovery code is invalid.'
                    : 'The provided two-factor authentication code is invalid.',
            );
        }

        $this->consumeChallengeToken($plainTextToken);

        return $user;
    }

    /**
     * @return array<int, string>
     */
    private function generateRecoveryCodes(): array
    {
        return DB::transaction(function (): array {
            $codes = [];

            for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
                $codes[] = strtoupper(bin2hex(random_bytes(4)));
            }

            return $codes;
        });
    }
}
