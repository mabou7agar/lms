<?php

use App\Platform\Identity\Enums\ApiScope;
use App\Platform\Identity\Http\Controllers\Api\V1\DeveloperApiKeyController;
use App\Platform\Identity\Http\Controllers\Api\V1\DeveloperController;
use App\Platform\Identity\Http\Controllers\OpenApiController;
use App\Platform\Identity\Http\Middleware\TrackTokenUsage;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

/*
 | Public API platform (Identity).
 |
 | This file is loaded inside the framework 'api' middleware group under the '/api' prefix, so
 | 'openapi.json' resolves at /api/openapi.json and the 'v1/*' groups at /api/v1/*.
 |
 | Sanctum's CheckAbilities middleware is applied by class-string (Class::class.':scope') rather
 | than by the 'abilities' alias, because that alias is not registered in this app's kernel — using
 | the class directly needs no alias wiring. `keys:manage` is an INTERNAL ability outside the public
 | ApiScope catalog: first-party login tokens carry '*' and satisfy it, while a scoped developer key
 | never can, so a developer key cannot manage keys.
 */

// Public OpenAPI 3.1 document for the developer surface.
Route::get('openapi.json', [OpenApiController::class, 'show'])->name('api.openapi');

// First-party org-admin key management (create / list / revoke). Tenant-scoped in the controller.
Route::prefix('v1')
    ->middleware(['auth:sanctum', CheckAbilities::class.':keys:manage'])
    ->group(function (): void {
        Route::get('api-keys', [DeveloperApiKeyController::class, 'index'])->name('api.v1.api-keys.index');
        Route::post('api-keys', [DeveloperApiKeyController::class, 'store'])->name('api.v1.api-keys.store');
        Route::delete('api-keys/{token}', [DeveloperApiKeyController::class, 'destroy'])
            ->whereNumber('token')
            ->name('api.v1.api-keys.destroy');
    });

// Scoped developer READ surface: auth:sanctum + per-key throttle + last-used tracking, then each
// endpoint additionally requires its matching Sanctum token ability.
Route::prefix('v1/developer')
    ->middleware(['auth:sanctum', TrackTokenUsage::class, 'throttle:developer-api'])
    ->group(function (): void {
        Route::get('account', [DeveloperController::class, 'account'])
            ->middleware(CheckAbilities::class.':'.ApiScope::AccountRead->value)
            ->name('api.v1.developer.account');

        Route::get('organization', [DeveloperController::class, 'organization'])
            ->middleware(CheckAbilities::class.':'.ApiScope::OrgRead->value)
            ->name('api.v1.developer.organization');

        Route::get('seats', [DeveloperController::class, 'seats'])
            ->middleware(CheckAbilities::class.':'.ApiScope::SeatsRead->value)
            ->name('api.v1.developer.seats');

        Route::get('usage', [DeveloperController::class, 'usage'])
            ->middleware(CheckAbilities::class.':'.ApiScope::UsageRead->value)
            ->name('api.v1.developer.usage');
    });
