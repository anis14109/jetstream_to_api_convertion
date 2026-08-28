<?php

use App\Http\Controllers\Api\ApiTokenController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PasswordConfirmationController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\TwoFactorChallengeController;
use App\Http\Controllers\Api\TwoFactorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes (Unauthenticated)
|--------------------------------------------------------------------------
*/

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/two-factor-challenge', TwoFactorChallengeController::class);

Route::post('/forgot-password', [PasswordResetController::class, 'forgotPassword']);
Route::post('/reset-password', [PasswordResetController::class, 'resetPassword']);

/*
|--------------------------------------------------------------------------
| Authenticated Routes
|--------------------------------------------------------------------------
*/

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
