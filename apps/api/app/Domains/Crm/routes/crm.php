<?php

use App\Domains\Crm\Http\Controllers\Api\V1\ConsultingController;
use App\Domains\Crm\Http\Controllers\Api\V1\CrmTaskController;
use App\Domains\Crm\Http\Controllers\Api\V1\LeadController;
use App\Domains\Crm\Http\Controllers\Api\V1\OpportunityController;
use App\Domains\Crm\Http\Controllers\Api\V1\OrganizationController;
use Illuminate\Support\Facades\Route;

/*
 | CRM endpoints (authenticated). Base 'api' prefix + these => /api/v1/*.
 */
Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    Route::get('organizations', [OrganizationController::class, 'index']);
    Route::get('organizations/{organization}', [OrganizationController::class, 'show']);
    Route::post('organizations/{organization}/members', [OrganizationController::class, 'storeMember']);

    Route::get('leads', [LeadController::class, 'index']);
    Route::post('leads', [LeadController::class, 'store']);
    Route::post('leads/{lead}/stage', [LeadController::class, 'moveStage']);
    Route::post('leads/{lead}/convert', [LeadController::class, 'convert']);

    Route::get('opportunities', [OpportunityController::class, 'index']);
    Route::post('opportunities', [OpportunityController::class, 'store']);
    Route::post('opportunities/{opportunity}/stage', [OpportunityController::class, 'moveStage']);

    Route::post('tasks', [CrmTaskController::class, 'store']);
    Route::post('tasks/{task}/complete', [CrmTaskController::class, 'complete']);

    Route::get('consulting', [ConsultingController::class, 'index']);
    Route::post('consulting/request', [ConsultingController::class, 'store']);
});
