<?php

use App\Platform\Identity\Http\Controllers\Api\V1\LinkedAccountController;
use App\Platform\Identity\Http\Controllers\Api\V1\SamlController;
use App\Platform\Identity\Http\Controllers\Api\V1\SsoCapabilitiesController;
use App\Platform\Identity\Http\Controllers\Api\V1\SsoDomainMappingController;
use Illuminate\Support\Facades\Route;

/*
 | SSO OPERATIONS (Identity). Base prefix 'api' + 'v1' => /api/v1/*.
 |
 |  - Linked accounts: the caller manages their OWN linked providers (list + unlink, orphan-safe).
 |  - SSO capabilities: data-driven map (OIDC supported, SAML unsupported) for the settings UI.
 |  - Domain mappings: org-admin, tenant-scoped CRUD (policy-gated in the controller).
 |  - SAML: explicitly UNSUPPORTED — metadata/ACS fail closed with 501 and never accept an assertion.
 */
Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    // Linked social/SSO accounts (any authenticated user, own accounts only).
    Route::get('account/linked-accounts', [LinkedAccountController::class, 'index']);
    Route::delete('account/linked-accounts/{socialAccount}', [LinkedAccountController::class, 'destroy']);

    // SSO capability map (drives the honest "SAML unsupported" notice).
    Route::get('sso/capabilities', [SsoCapabilitiesController::class, 'show']);

    // Org-admin, tenant-scoped domain mappings.
    Route::get('sso/domains', [SsoDomainMappingController::class, 'index']);
    Route::post('sso/domains', [SsoDomainMappingController::class, 'store']);
    Route::patch('sso/domains/{ssoDomainMapping}', [SsoDomainMappingController::class, 'update']);
    Route::delete('sso/domains/{ssoDomainMapping}', [SsoDomainMappingController::class, 'destroy']);
    // Verification stub — super_admin-only (enforced by SsoDomainMappingPolicy).
    Route::post('sso/domains/{ssoDomainMapping}/verify', [SsoDomainMappingController::class, 'verify']);
});

/*
 | SAML is UNSUPPORTED and fails closed. Public (no auth) so an IdP hitting the ACS/metadata gets a
 | clear 501 rather than a 401 — but NO assertion is ever consumed. Never wire a real ACS here without
 | XML-DSIG signed-assertion verification.
 */
Route::prefix('v1/sso/saml')->group(function (): void {
    Route::get('metadata', [SamlController::class, 'metadata']);
    Route::match(['get', 'post'], 'acs', [SamlController::class, 'acs']);
});
