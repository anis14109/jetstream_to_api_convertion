<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TwoFactor\ChallengeRequest;
use App\Http\Resources\Api\V1\SessionResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Api\V1\AuthService;
use App\Services\Api\V1\TwoFactorService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class TwoFactorChallengeController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly TwoFactorService $twoFactor,
    ) {}

    public function __invoke(ChallengeRequest $request): JsonResponse
    {
        $user = $this->twoFactor->completeChallenge(
            $request->validated('two_factor_token'),
            $request->validated('code'),
            $request->validated('recovery_code'),
        );

        $result = $this->auth->authenticate(
            $user,
            $request->validated('device_name') ?? 'API client',
            $request,
        );

        return ApiResponse::success([
            'user' => new UserResource($result['user']),
            'access_token' => $result['access_token'],
            'token_type' => $result['token_type'],
            'access_expires_at' => $result['access_expires_at'],
            'refresh_token' => $result['refresh_token'],
            'refresh_expires_at' => $result['refresh_expires_at'],
            'session' => new SessionResource($result['session'], true),
        ], 'Two-factor authentication successful.');
    }
}
