<?php

use App\Platform\Identity\Http\Controllers\Api\V1\PrivacyController;
use Illuminate\Support\Facades\Route;

/*
 | Self-service privacy routes (PDPL/GDPR). Base prefix 'api' + 'v1' => /api/v1/privacy/*.
 | All authenticated: a data subject acts only on their own data.
 */
Route::prefix('v1/privacy')->middleware('auth:sanctum')->group(function (): void {
    Route::get('consents', [PrivacyController::class, 'consents']);
    Route::post('consents', [PrivacyController::class, 'recordConsent']);
    Route::get('export', [PrivacyController::class, 'export']);
    Route::get('data-requests', [PrivacyController::class, 'listDataRequests']);
    Route::post('data-requests', [PrivacyController::class, 'submitDataRequest']);
});
