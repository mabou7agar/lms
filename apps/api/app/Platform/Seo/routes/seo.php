<?php

use App\Platform\Seo\Http\Controllers\Api\V1\SeoController;
use Illuminate\Support\Facades\Route;

/*
 | Centralized SEO Manager endpoints. Base 'api' prefix + these => /api/v1/seo*.
 |  - GET /seo                       admin; the manager list of stored SEO records (+ warnings).
 |  - GET /seo/sitemap               public; sitemap-enabled indexable entities (url/priority/freq).
 |  - GET /seo/{entityType}/{key}    public; the resolved SEO payload consumed by generateMetadata.
 |
 | The two fixed one-segment routes are declared before the two-segment {entityType}/{key} route so
 | they win; an unknown entityType 404s in the controller.
 */
Route::prefix('v1')->group(function (): void {
    Route::get('seo', [SeoController::class, 'index'])->middleware('auth:sanctum');
    // M9 — throttle the two public SEO reads (the admin list above keeps its auth:sanctum guard).
    Route::get('seo/sitemap', [SeoController::class, 'sitemap'])->middleware('throttle:public-read');
    Route::get('seo/{entityType}/{key}', [SeoController::class, 'show'])
        ->middleware('throttle:public-read')
        ->where('key', '[A-Za-z0-9\-_./]+');
});
