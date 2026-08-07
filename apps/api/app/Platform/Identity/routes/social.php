<?php

use App\Platform\Identity\Http\Controllers\Api\V1\SocialAuthController;
use Illuminate\Support\Facades\Route;

/*
 | Social / SSO auth routes. Base prefix 'api' + 'v1' => /api/v1/auth/social/*.
 | Public (pre-authentication) and throttled by the identity-social limiter defined in
 | IdentityServiceProvider. The provider key is validated by SocialAuthManager against config('sso').
 */
Route::prefix('v1/auth/social')->group(function (): void {
    Route::get('{provider}/redirect', [SocialAuthController::class, 'redirect'])
        ->middleware('throttle:identity-social')
        ->where('provider', '[a-z0-9_-]+');

    Route::post('{provider}/callback', [SocialAuthController::class, 'callback'])
        ->middleware('throttle:identity-social')
        ->where('provider', '[a-z0-9_-]+');
});
