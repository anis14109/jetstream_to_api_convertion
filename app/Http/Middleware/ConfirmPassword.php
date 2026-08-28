<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class ConfirmPassword
{
    /**
     * Handle an incoming request.
     *
     * Checks if the user has confirmed their password within the
     * configured timeout period (default: 3 hours) using cache
     * instead of sessions for stateless API compatibility.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $cacheKey = 'password_confirmation_'.$user->id;
        $confirmedAt = Cache::get($cacheKey);

        $timeout = config('auth.password_timeout', 10800);

        if ($confirmedAt && now()->timestamp - $confirmedAt <= $timeout) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Password confirmation required.',
        ], 403);
    }
}
