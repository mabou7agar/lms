<?php

use App\Domains\Crm\Http\Controllers\Api\V1\PublicLeadController;
use Illuminate\Support\Facades\Route;

/*
 | PUBLIC CRM endpoints (GUEST — no auth:sanctum). Base 'api' prefix + these => /api/v1/public/*.
 | The only public write is the enterprise-lead funnel; it is rate-limited by the named
 | 'crm-public-lead' limiter (IP + email) registered in CrmServiceProvider.
 */
Route::prefix('v1')->middleware('throttle:crm-public-lead')->group(function (): void {
    Route::post('public/leads', [PublicLeadController::class, 'store']);
});
