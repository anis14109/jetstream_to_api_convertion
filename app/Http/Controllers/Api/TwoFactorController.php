<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorController extends Controller
{
    /**
     * Enable two-factor authentication for the authenticated user.
     * Returns the secret key and recovery codes. The user must confirm
     * the setup by providing a valid TOTP code.
     */
    public function enable(Request $request): JsonResponse
    {
        $user = $request->user();

        $google2fa = new Google2FA;

        $secret = $google2fa->generateSecretKey();

        $recoveryCodes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json([
            'secret' => $secret,
            'recovery_codes' => $recoveryCodes,
            'qr_code' => $google2fa->getQRCodeUrl(
                config('app.name'),
                $user->email,
                $secret
            ),
        ]);
    }

    /**
     * Confirm two-factor authentication by verifying a TOTP code.
     */
    public function confirm(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string', 'digits:6'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        if (! $user->two_factor_secret) {
            return response()->json([
                'message' => 'Two-factor authentication has not been enabled.',
            ], 422);
        }

        $google2fa = new Google2FA;
        $secret = decrypt($user->two_factor_secret);

        if (! $google2fa->verifyKey($secret, $request->code)) {
            return response()->json([
                'message' => 'The provided two-factor authentication code is invalid.',
            ], 422);
        }

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
        ])->save();

        return response()->json(['message' => 'Two-factor authentication confirmed.']);
    }

    /**
     * Disable two-factor authentication for the authenticated user.
     */
    public function disable(Request $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return response()->json(['message' => 'Two-factor authentication disabled.']);
    }

    /**
     * Regenerate recovery codes for the authenticated user.
     */
    public function recoveryCodes(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->two_factor_secret) {
            return response()->json([
                'message' => 'Two-factor authentication has not been enabled.',
            ], 422);
        }

        $recoveryCodes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
        ])->save();

        return response()->json(['recovery_codes' => $recoveryCodes]);
    }

    /**
     * Generate 8 random recovery codes.
     */
    private function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)));
        }

        return $codes;
    }
}
