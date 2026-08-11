<?php

declare(strict_types=1);

use App\Platform\Integration\Http\Controllers\Api\V1\WebhookDeliveryController;
use App\Platform\Integration\Http\Controllers\Api\V1\WebhookEndpointController;
use Illuminate\Support\Facades\Route;

/*
 | Outbound Integration (customer webhook) management API. Base 'api' prefix + these => /api/v1/*.
 | All routes are authenticated; endpoints/deliveries are org-scoped by the models' tenant scope.
 | {endpoint} binds a WebhookEndpoint by public_id; {delivery} is scoped to its endpoint.
 */
Route::prefix('v1/integration')->middleware('auth:sanctum')->group(function (): void {
    Route::get('webhook-endpoints', [WebhookEndpointController::class, 'index']);
    Route::post('webhook-endpoints', [WebhookEndpointController::class, 'store']);
    Route::get('webhook-endpoints/{endpoint}', [WebhookEndpointController::class, 'show']);
    Route::patch('webhook-endpoints/{endpoint}', [WebhookEndpointController::class, 'update']);
    Route::post('webhook-endpoints/{endpoint}/rotate-secret', [WebhookEndpointController::class, 'rotateSecret']);
    Route::post('webhook-endpoints/{endpoint}/enable', [WebhookEndpointController::class, 'enable']);
    Route::post('webhook-endpoints/{endpoint}/disable', [WebhookEndpointController::class, 'disable']);

    Route::get('webhook-endpoints/{endpoint}/deliveries', [WebhookDeliveryController::class, 'index']);
    Route::post('webhook-endpoints/{endpoint}/deliveries/{delivery}/replay', [WebhookDeliveryController::class, 'replay'])
        ->scopeBindings();
});
