<?php

use App\Platform\Identity\Http\Controllers\Api\V1\AuthController;
use App\Platform\Identity\Http\Controllers\Api\V1\MfaController;
use Illuminate\Support\Facades\Route;

/*
 | Identity auth routes. Base prefix 'api' + 'v1' => /api/v1/auth/*.
 | Named rate limiters (identity-*) are defined in IdentityServiceProvider.
 */
Route::prefix('v1/auth')->group(function (): void {
    // Public
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:identity-register');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:identity-login');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:identity-password');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:identity-password');

    // Authenticated
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('verify-email', [AuthController::class, 'verifyEmail'])->middleware('throttle:identity-otp-verify');
        Route::post('verify-phone', [AuthController::class, 'verifyPhone'])->middleware('throttle:identity-otp-verify');

        Route::post('mfa/enable', [MfaController::class, 'enable']);
        Route::post('mfa/verify', [MfaController::class, 'verify'])->middleware('throttle:identity-otp-verify');
        Route::post('mfa/disable', [MfaController::class, 'disable'])->middleware('throttle:identity-otp-verify');
    });
});
