<?php

use App\Contexts\Commerce\Http\Controllers\Api\V1\CartController;
use App\Contexts\Commerce\Http\Controllers\Api\V1\CheckoutController;
use App\Contexts\Commerce\Http\Controllers\Api\V1\ContractController;
use App\Contexts\Commerce\Http\Controllers\Api\V1\OrderController;
use App\Contexts\Commerce\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Contexts\Commerce\Http\Controllers\Api\V1\ProductController;
use Illuminate\Support\Facades\Route;

/*
 | Commerce endpoints. Base 'api' prefix + these => /api/v1/*.
 */
Route::prefix('v1')->group(function (): void {
    // Public listing
    Route::get('products', [ProductController::class, 'index']);

    // Provider webhook (public; signature verified inside the gateway). Throttled per source IP as
    // defence in depth — the signature is the real control (see commerce-webhook limiter).
    Route::post('payment/webhook', [PaymentWebhookController::class, 'handle'])
        ->middleware('throttle:commerce-webhook');

    // Authenticated commerce
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('cart', [CartController::class, 'show']);
        Route::post('cart', [CartController::class, 'store']);
        Route::delete('cart/items/{product}', [CartController::class, 'removeItem']);
        Route::delete('cart', [CartController::class, 'destroy']);

        Route::post('checkout', [CheckoutController::class, 'store'])
            ->middleware('throttle:commerce-checkout');

        Route::get('orders', [OrderController::class, 'index']);

        Route::get('contracts', [ContractController::class, 'index']);
        Route::post('contracts/{contract}/accept', [ContractController::class, 'accept']);
    });
});
