<?php

use App\Platform\Media\Http\Controllers\Api\V1\MediaWebhookController;
use Illuminate\Support\Facades\Route;

/*
 | P2/W04 - Provider webhook endpoints (public; signature verified inside each adapter). No Sanctum,
 | no CSRF, no tenant resolution. Throttled per source IP as defence in depth — the signature is the
 | real control. Base 'api' prefix + these => /api/v1/media/webhooks/*.
 */
// Inline throttle (no named limiter dependency): defence in depth only; the signature is the control.
Route::prefix('v1/media/webhooks')->middleware('throttle:120,1')->group(function (): void {
    Route::post('mux', [MediaWebhookController::class, 'mux']);
    Route::post('s3', [MediaWebhookController::class, 's3']);
    Route::post('fake', [MediaWebhookController::class, 'fake']);
});
