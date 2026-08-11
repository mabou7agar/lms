<?php

use App\Platform\Branding\Http\Controllers\Api\V1\BrandingController;
use App\Platform\Branding\Http\Controllers\Api\V1\CustomDomainController;
use App\Platform\Branding\Http\Controllers\Api\V1\OrganizationBrandingController;
use Illuminate\Support\Facades\Route;

/*
 | Branding / white-label endpoints. Base 'api' prefix + this => /api/v1/*.
 |
 |  PUBLIC (read-only, cacheable):
 |   - GET /branding   the defaults-merged branding payload, RESOLVED BY HOST. A verified custom
 |                     domain yields that org's merged brand; any other host falls back to the global
 |                     brand (payload shape unchanged, only values differ).
 |
 |  ORG-ADMIN (auth:sanctum, tenant-scoped, policy-guarded — an org only ever touches its OWN data):
 |   - GET|PUT|PATCH /org/branding          read/update the caller org's brand override.
 |   - GET|POST      /org/domains           list/add the caller org's custom domains.
 |   - DELETE        /org/domains/{...}      remove an own-org domain.
 |   - POST          /org/domains/{...}/verify   super_admin-only verification stub.
 */
Route::prefix('v1')->group(function (): void {
    // M9 — per-page-load config payload; throttled via the shared public-config limiter.
    Route::get('branding', [BrandingController::class, 'show'])->middleware('throttle:public-config');
});

Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    // Org-admin brand override (self-scoped to the caller's organization).
    Route::get('org/branding', [OrganizationBrandingController::class, 'show']);
    Route::match(['put', 'patch'], 'org/branding', [OrganizationBrandingController::class, 'update']);

    // Org-admin custom domains (tenant-scoped CRUD; verify is super_admin-only).
    Route::get('org/domains', [CustomDomainController::class, 'index']);
    Route::post('org/domains', [CustomDomainController::class, 'store']);
    Route::delete('org/domains/{customDomain}', [CustomDomainController::class, 'destroy']);
    Route::post('org/domains/{customDomain}/verify', [CustomDomainController::class, 'verify']);
});
