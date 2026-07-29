<?php

use App\Domains\Live\Http\Controllers\Api\V1\Admin\LiveSessionAdminController;
use App\Domains\Live\Http\Controllers\Api\V1\LiveSessionController;
use App\Domains\Live\Http\Controllers\Api\V1\SessionParticipationController;
use Illuminate\Support\Facades\Route;

/*
 | Live Learning endpoints. Base 'api' prefix + these => /api/v1/*.
 */
Route::prefix('v1')->group(function (): void {
    // Public listing/detail (M9 — throttled; the rest of this file is auth:sanctum).
    Route::middleware('throttle:public-read')->group(function (): void {
        Route::get('live-sessions', [LiveSessionController::class, 'index']);
        Route::get('live-sessions/{session}', [LiveSessionController::class, 'show']);
    });

    // Authenticated participation
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('live-sessions/{session}/register', [SessionParticipationController::class, 'register']);
        Route::post('live-sessions/{session}/join', [SessionParticipationController::class, 'join']);
        Route::post('live-sessions/{session}/attendance', [SessionParticipationController::class, 'attendance']);

        // Admin scheduling
        Route::post('admin/live-sessions', [LiveSessionAdminController::class, 'store']);
        Route::put('admin/live-sessions/{session}/reschedule', [LiveSessionAdminController::class, 'reschedule']);
        Route::post('admin/live-sessions/{session}/start', [LiveSessionAdminController::class, 'start']);
        Route::post('admin/live-sessions/{session}/complete', [LiveSessionAdminController::class, 'complete']);
        Route::post('admin/live-sessions/{session}/cancel', [LiveSessionAdminController::class, 'cancel']);
    });
});
