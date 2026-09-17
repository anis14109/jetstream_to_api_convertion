<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Sync\AckRequest;
use App\Http\Requests\Api\V1\Sync\PullRequest;
use App\Http\Requests\Api\V1\Sync\PushRequest;
use App\Http\Resources\Api\V1\ChangeResource;
use App\Services\Api\V1\AuthService;
use App\Support\ApiResponse;
use App\Support\Enums\ApiErrorCode;
use App\Sync\SyncEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function __construct(
        private readonly SyncEngine $sync,
        private readonly AuthService $auth,
    ) {}

    public function cursor(Request $request): JsonResponse
    {
        $cursor = $this->sync->currentCursor($request->user(), $this->auth->clientKey($request));

        return ApiResponse::success($cursor, 'Sync cursor.');
    }

    public function pull(PullRequest $request): JsonResponse
    {
        $result = $this->sync->pull(
            $request->user(),
            $this->auth->clientKey($request),
            $request->validated('cursor'),
            (int) ($request->validated('limit') ?? config('api.sync.pull_batch_size')),
        );

        return ApiResponse::success([
            'changes' => ChangeResource::collection($result['items']),
            'next_cursor' => $result['next_cursor'],
            'has_more' => $result['has_more'],
            'server_time' => $result['server_time'],
        ], 'Changes pulled.');
    }

    public function ack(AckRequest $request): JsonResponse
    {
        $result = $this->sync->ack(
            $request->user(),
            $this->auth->clientKey($request),
            (int) $request->validated('cursor'),
        );

        return ApiResponse::success($result, 'Changes acknowledged.');
    }

    public function push(PushRequest $request): JsonResponse
    {
        $result = $this->sync->push(
            $request->user(),
            $this->auth->clientKey($request),
            $request->validated('operations'),
        );

        if ($result['idempotency_conflicts'] !== []) {
            return ApiResponse::error(
                'One or more operation ids were reused with a different payload.',
                ApiErrorCode::IdempotencyConflict,
                409,
                [],
                $result,
            );
        }

        if ($result['pending'] !== []) {
            return ApiResponse::error(
                'One or more operations are still being processed. Retry shortly.',
                ApiErrorCode::IdempotencyInProgress,
                409,
                [],
                $result,
            );
        }

        if ($result['conflicts'] !== []) {
            return ApiResponse::error(
                'One or more operations conflict with the server state.',
                ApiErrorCode::SyncConflict,
                409,
                [],
                $result,
            );
        }

        return ApiResponse::success($result, 'Sync push processed.');
    }
}
