<?php

use App\Platform\Branding\Http\Controllers\Api\V1\BrandingController;
use Illuminate\Support\Facades\Route;

/*
 | Branding / white-label endpoint. Base 'api' prefix + this => /api/v1/branding.
 |  - GET /branding   public, read-only, cacheable (the defaults-merged branding payload).
 */
Route::prefix('v1')->group(function (): void {
    // M9 — per-page-load config payload; throttled via the shared public-config limiter.
    Route::get('branding', [BrandingController::class, 'show'])->middleware('throttle:public-config');
});
