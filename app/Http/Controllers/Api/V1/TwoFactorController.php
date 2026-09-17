<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TwoFactor\ConfirmTwoFactorRequest;
use App\Services\Api\V1\TwoFactorService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoFactorController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    /**
     * Begin 2FA setup. The returned secret is required to complete setup and
     * is never exposed again afterwards.
     */
    public function enable(Request $request): JsonResponse
    {
        $data = $this->twoFactor->enable($request->user());

        return ApiResponse::success($data, 'Two-factor authentication setup initialized.');
    }

    public function confirm(ConfirmTwoFactorRequest $request): JsonResponse
    {
        $this->twoFactor->confirm($request->user(), $request->validated('code'));

        return ApiResponse::success(null, 'Two-factor authentication confirmed.');
    }

    public function disable(Request $request): JsonResponse
    {
        $this->twoFactor->disable($request->user());

        return ApiResponse::success(null, 'Two-factor authentication disabled.');
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $codes = $this->twoFactor->regenerateRecoveryCodes($request->user());

        return ApiResponse::success(['recovery_codes' => $codes], 'Recovery codes regenerated.');
    }
}
