<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\User\DeleteAccountRequest;
use App\Http\Requests\Api\V1\User\UpdatePasswordRequest;
use App\Http\Requests\Api\V1\User\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\Api\V1\AuthService;
use App\Services\Api\V1\ProfileService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(
        private readonly ProfileService $profiles,
        private readonly AuthService $auth,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(new UserResource($request->user()), 'Profile.');
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->profiles->updateProfile($request->user(), $request->validated());

        return ApiResponse::success(new UserResource($user), 'Profile updated.');
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $this->profiles->updatePassword(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('password'),
            $this->auth->currentSession($request)?->getKey(),
        );

        return ApiResponse::success(null, 'Password updated.');
    }

    public function destroy(DeleteAccountRequest $request): JsonResponse
    {
        $this->profiles->deleteAccount($request->user(), $request->validated('password'));

        return ApiResponse::success(null, 'Account deleted.');
    }
}
