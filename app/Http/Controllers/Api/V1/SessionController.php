<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SessionResource;
use App\Models\AuthSession;
use App\Services\Api\V1\AuthService;
use App\Services\Api\V1\RefreshTokenService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly RefreshTokenService $refreshTokens,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $current = $this->auth->currentSession($request);

        $sessions = AuthSession::query()
            ->where('user_id', $request->user()->getKey())
            ->whereNull('revoked_at')
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn (AuthSession $session): array => (new SessionResource(
                $session,
                $current?->getKey() === $session->getKey(),
            ))->resolve($request))
            ->all();

        return ApiResponse::success($sessions, 'Active sessions.');
    }

    public function destroy(Request $request, AuthSession $session): JsonResponse
    {
        if ($session->user_id !== $request->user()->getKey()) {
            throw ApiException::notFound('Session not found.');
        }

        $this->refreshTokens->revokeSession($session);

        return ApiResponse::success(null, 'Session revoked.');
    }
}
