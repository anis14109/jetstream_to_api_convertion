<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class PasswordConfirmationController extends Controller
{
    /**
     * Confirm the user's password for sensitive operations.
     * Stores confirmation in cache (not session) for API compatibility.
     */
    public function confirm(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if (! Hash::check($request->password, $request->user()->password)) {
            return response()->json([
                'errors' => ['password' => ['The password is incorrect.']],
            ], 422);
        }

        // Store confirmation in cache with the configured timeout
        $cacheKey = 'password_confirmation_'.$request->user()->id;
        Cache::put($cacheKey, now()->timestamp, config('auth.password_timeout', 10800));

        return response()->json(['message' => 'Password confirmed.']);
    }
}
