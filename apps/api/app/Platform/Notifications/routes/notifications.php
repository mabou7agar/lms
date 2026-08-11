<?php

use App\Platform\Notifications\Http\Controllers\Api\V1\NotificationController;
use App\Platform\Notifications\Http\Controllers\Api\V1\PreferenceController;
use App\Platform\Notifications\Http\Controllers\Api\V1\UnsubscribeController;
use Illuminate\Support\Facades\Route;

/*
 | Notification-center endpoints (authenticated). Base 'api' prefix + these => /api/v1/*.
 */
Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/{notification}', [NotificationController::class, 'show']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read']);
    Route::post('notifications/preferences', [PreferenceController::class, 'update']);
});

/*
 | PUBLIC marketing unsubscribe (GUEST — no auth). The `signed` middleware requires a valid URL
 | signature over the whole link (email + category + tenant), so the token is mandatory and cannot be
 | repurposed. Only the marketing category is unsubscribable; transactional messages are never
 | suppressed (enforced in the controller).
 */
Route::prefix('v1')->middleware('signed')->group(function (): void {
    Route::get('marketing/unsubscribe', UnsubscribeController::class)->name('marketing.unsubscribe');
});
