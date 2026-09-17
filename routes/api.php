<?php

use App\Http\Controllers\Api\ApiTokenController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PasswordConfirmationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\TwoFactorChallengeController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\V1\AuthController as V1AuthController;
use App\Http\Controllers\Api\V1\PasswordConfirmationController as V1PasswordConfirmationController;
use App\Http\Controllers\Api\V1\PasswordResetController as V1PasswordResetController;
use App\Http\Controllers\Api\V1\ProfileController as V1ProfileController;
use App\Http\Controllers\Api\V1\SessionController as V1SessionController;
use App\Http\Controllers\Api\V1\StudentController as V1StudentController;
use App\Http\Controllers\Api\V1\SyncController as V1SyncController;
use App\Http\Controllers\Api\V1\TwoFactorChallengeController as V1TwoFactorChallengeController;
use App\Http\Controllers\Api\V1\TwoFactorController as V1TwoFactorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Legacy Routes (unversioned)
|--------------------------------------------------------------------------
| These routes power the existing first-party clients and must keep their
| original response shapes for backwards compatibility. New clients should
| use the versioned routes below.
*/

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/two-factor-challenge', TwoFactorChallengeController::class);

Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);

Route::middleware('auth:sanctum')->group(function () {

    // Authentication
    Route::post('/logout', [AuthController::class, 'logout']);

    // Profile
    Route::get('/user', [ProfileController::class, 'show']);
    Route::put('/user/profile-information', [ProfileController::class, 'update']);
    Route::put('/user/password', [ProfileController::class, 'updatePassword']);
    Route::delete('/user', [ProfileController::class, 'destroy']);

    // Password Confirmation
    Route::post('/user/confirm-password', [PasswordConfirmationController::class, 'confirm']);

    // Two-Factor Authentication (password confirmation required to enable)
    Route::post('/two-factor-enable', [TwoFactorController::class, 'enable'])->middleware('confirm-password');
    Route::post('/two-factor-confirm', [TwoFactorController::class, 'confirm']);
    Route::delete('/two-factor-disable', [TwoFactorController::class, 'disable']);
    Route::put('/two-factor-recovery-codes', [TwoFactorController::class, 'recoveryCodes']);

    // API Tokens
    Route::get('/api-tokens', [ApiTokenController::class, 'index']);
    Route::post('/api-tokens', [ApiTokenController::class, 'store']);
    Route::put('/api-tokens/{tokenId}', [ApiTokenController::class, 'update']);
    Route::delete('/api-tokens/{tokenId}', [ApiTokenController::class, 'destroy']);
});

/*
|--------------------------------------------------------------------------
| Version 1 Routes
|--------------------------------------------------------------------------
| Stable, envelope-based REST API for Flutter, Vue.js and Python clients.
| Breaking changes are introduced as /api/v2 while v1 remains supported.
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {

    /*
    |----------------------------------------------------------------------
    | Public Routes (Unauthenticated)
    |----------------------------------------------------------------------
    */

    Route::middleware('throttle:api-register')
        ->post('/auth/register', [V1AuthController::class, 'register'])
        ->name('auth.register');

    Route::middleware('throttle:api-login')
        ->post('/auth/login', [V1AuthController::class, 'login'])
        ->name('auth.login');

    Route::middleware('throttle:api-refresh')
        ->post('/auth/refresh', [V1AuthController::class, 'refresh'])
        ->name('auth.refresh');

    Route::middleware('throttle:api-two-factor')
        ->post('/auth/two-factor-challenge', V1TwoFactorChallengeController::class)
        ->name('auth.two-factor-challenge');

    Route::middleware('throttle:api-password-reset')->group(function (): void {
        Route::post('/auth/forgot-password', [V1PasswordResetController::class, 'forgotPassword'])
            ->name('password.email');
        Route::post('/auth/reset-password', [V1PasswordResetController::class, 'resetPassword'])
            ->name('password.update');
    });

    /*
    |----------------------------------------------------------------------
    | Authenticated Routes
    |----------------------------------------------------------------------
    */

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {

        // Authentication
        Route::post('/auth/logout', [V1AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/auth/logout-all', [V1AuthController::class, 'logoutAll'])->name('auth.logout-all');
        Route::get('/auth/me', [V1AuthController::class, 'me'])->name('auth.me');

        // Profile
        Route::get('/user', [V1ProfileController::class, 'show'])->name('user.show');
        Route::match(['put', 'patch'], '/user', [V1ProfileController::class, 'update'])->name('user.update');
        Route::put('/user/password', [V1ProfileController::class, 'updatePassword'])->name('user.password');
        Route::delete('/user', [V1ProfileController::class, 'destroy'])->name('user.destroy');

        // Password Confirmation
        Route::post('/user/confirm-password', [V1PasswordConfirmationController::class, 'confirm'])
            ->name('user.confirm-password');

        // Sessions
        Route::get('/sessions', [V1SessionController::class, 'index'])->name('sessions.index');
        Route::delete('/sessions/{session}', [V1SessionController::class, 'destroy'])->name('sessions.destroy');

        // Two-Factor Authentication (password confirmation required)
        Route::middleware('api-confirm-password')->group(function (): void {
            Route::post('/two-factor/enable', [V1TwoFactorController::class, 'enable'])->name('two-factor.enable');
            Route::post('/two-factor/confirm', [V1TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
            Route::delete('/two-factor', [V1TwoFactorController::class, 'disable'])->name('two-factor.disable');
            Route::get('/two-factor/recovery-codes', [V1TwoFactorController::class, 'recoveryCodes'])
                ->name('two-factor.recovery-codes');
        });

        // Offline sync (more generous rate limit than the default API bucket)
        Route::middleware('throttle:api-sync')->group(function (): void {
            Route::get('/sync/cursor', [V1SyncController::class, 'cursor'])->name('sync.cursor');
            Route::get('/sync/pull', [V1SyncController::class, 'pull'])->name('sync.pull');
            Route::post('/sync/push', [V1SyncController::class, 'push'])->name('sync.push');
        });

        // Students (example synchronizable resource)
        Route::get('/students', [V1StudentController::class, 'index'])->name('students.index');
        Route::post('/students', [V1StudentController::class, 'store'])->name('students.store');
        Route::get('/students/{student}', [V1StudentController::class, 'show'])->name('students.show');
        Route::match(['put', 'patch'], '/students/{student}', [V1StudentController::class, 'update'])
            ->name('students.update');
        Route::delete('/students/{student}', [V1StudentController::class, 'destroy'])->name('students.destroy');
    });
});
