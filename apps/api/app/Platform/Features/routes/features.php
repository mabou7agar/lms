<?php

use App\Platform\Features\Http\Controllers\Api\V1\FeatureFlagController;
use Illuminate\Support\Facades\Route;

/*
 | Feature flags endpoint. Base 'api' prefix + this => /api/v1/feature-flags.
 |  - GET /feature-flags  auth OPTIONAL. Returns the resolved boolean map for the current user
 |    (or the anonymous/guest map when unauthenticated) so the frontend can gate UI. Only boolean
 |    keys are emitted — never any flag internals.
 */
Route::prefix('v1')->group(function (): void {
    // M9 — per-page-load config payload; throttled via the shared public-config limiter.
    Route::get('feature-flags', [FeatureFlagController::class, 'index'])->middleware('throttle:public-config');
});
