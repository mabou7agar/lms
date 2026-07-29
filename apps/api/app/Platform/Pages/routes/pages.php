<?php

use App\Platform\Pages\Http\Controllers\Api\V1\PageController;
use Illuminate\Support\Facades\Route;

/*
 | Static Pages CMS endpoints. Base 'api' prefix + these => /api/v1/pages*.
 |  - GET /pages                  public, read-only list of published pages (nav / sitemap feed).
 |  - GET /pages/{slug}           public, read-only full page (404 unless live).
 |  - GET /pages/{slug}/preview   authenticated + admin/super_admin (current draft in any status).
 |
 | {slug} is a plain string (pages are addressed by slug, not the public_id route key).
 */
Route::prefix('v1')->group(function (): void {
    // M9 — throttle the public reads; the admin preview keeps its auth:sanctum guard.
    Route::get('pages', [PageController::class, 'index'])->middleware('throttle:public-read');
    Route::get('pages/{slug}/preview', [PageController::class, 'preview'])
        ->middleware('auth:sanctum')
        ->where('slug', '[A-Za-z0-9\-_/]+');
    Route::get('pages/{slug}', [PageController::class, 'show'])
        ->middleware('throttle:public-read')
        ->where('slug', '[A-Za-z0-9\-_/]+');
});
