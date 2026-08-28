<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorChallengeController extends Controller
{
    /**
     * Complete two-factor authentication using a TOTP code or recovery code.
     * The request must include either:
     *  - `two_factor_token` (from the initial login) + `code` (TOTP)
     *  - `two_factor_token` + `recovery_code`
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'two_factor_token' => ['required', 'string'],
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'digits:6'],
            'recovery_code' => ['required_without:code', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $this->resolveUserFromToken($request->two_factor_token);

        if (! $user) {
            return response()->json([
                'message' => 'Invalid or expired two-factor token.',
            ], 401);
        }

        if ($request->recovery_code) {
            return $this->handleRecoveryCode($user, $request->recovery_code, $request->two_factor_token);
        }

        return $this->handleTwoFactorCode($user, $request->code, $request->two_factor_token);
    }

    /**
     * Resolve the user from the two-factor temporary token.
     */
    private function resolveUserFromToken(string $plainTextToken): ?User
    {
        $tokenParts = explode('|', $plainTextToken);

        if (count($tokenParts) !== 2) {
            return null;
        }

        $token = Sanctum::findAccessToken($plainTextToken);

        if (! $token) {
            return null;
        }

        // Verify the token has the two-factor-challenge ability
        if (! $token->can('two-factor-challenge')) {
            return null;
        }

        return $token->tokenable;
    }

    /**
     * Handle TOTP-based two-factor authentication.
     */
    private function handleTwoFactorCode(User $user, string $code, string $twoFactorToken): JsonResponse
    {
        $google2fa = new Google2FA;
        $secret = decrypt($user->two_factor_secret);

        if (! $google2fa->verifyKey($secret, $code)) {
            return response()->json([
                'message' => 'The provided two-factor authentication code is invalid.',
            ], 422);
        }

        // Delete the temporary 2FA token and issue a real auth token
        $this->revokeToken($twoFactorToken);
        $authToken = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $authToken,
        ]);
    }

    /**
     * Handle recovery code-based two-factor authentication.
     */
    private function handleRecoveryCode(User $user, string $recoveryCode, string $twoFactorToken): JsonResponse
    {
        $recoveryCodes = json_decode(decrypt($user->two_factor_recovery_codes), true) ?? [];

        $found = false;
        foreach ($recoveryCodes as $key => $code) {
            if (hash_equals($code, strtoupper($recoveryCode))) {
                unset($recoveryCodes[$key]);
                $found = true;
                break;
            }
        }

        if (! $found) {
            return response()->json([
                'message' => 'The provided recovery code is invalid.',
            ], 422);
        }

        // Save remaining recovery codes
        $user->forceFill([
            'two_factor_recovery_codes' => encrypt(json_encode(array_values($recoveryCodes))),
        ])->save();

        // Delete the temporary 2FA token and issue a real auth token
        $this->revokeToken($twoFactorToken);
        $authToken = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $authToken,
        ]);
    }

    /**
     * Revoke a token by its plain text value.
     */
    private function revokeToken(string $plainTextToken): void
    {
        $tokenParts = explode('|', $plainTextToken);

        if (count($tokenParts) === 2) {
            $token = Sanctum::findAccessToken($plainTextToken);
            if ($token) {
                $token->delete();
            }
        }
    }
}
