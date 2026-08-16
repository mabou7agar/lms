<?php

use App\Contexts\Commerce\Enums\CommercePermission;
use App\Contexts\Commerce\Http\Controllers\Api\V1\AdminCommerceAnalyticsController;
use App\Contexts\Commerce\Http\Controllers\Api\V1\AdminCouponController;
use App\Contexts\Commerce\Http\Controllers\Api\V1\AdminOrderController;
use App\Contexts\Commerce\Http\Controllers\Api\V1\AdminSubscriptionController;
use App\Contexts\Commerce\Http\Controllers\Api\V1\CartController;
use App\Contexts\Commerce\Http\Controllers\Api\V1\CheckoutController;
use App\Contexts\Commerce\Http\Controllers\Api\V1\ContractController;
use App\Contexts\Commerce\Http\Controllers\Api\V1\CouponController;
use App\Contexts\Commerce\Http\Controllers\Api\V1\CreditNoteController;
use App\Contexts\Commerce\Http\Controllers\Api\V1\EntitlementController;
use App\Contexts\Commerce\Http\Controllers\Api\V1\InvoiceController;
use App\Contexts\Commerce\Http\Controllers\Api\V1\OrderController;
use App\Contexts\Commerce\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Contexts\Commerce\Http\Controllers\Api\V1\ProductController;
use App\Contexts\Commerce\Http\Controllers\Api\V1\RefundController;
use App\Contexts\Commerce\Http\Controllers\Api\V1\SubscriptionController;
use App\Contexts\Commerce\Http\Controllers\Api\V1\SubscriptionPlanController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Commerce API routes (mounted under the api/v1 group)
|--------------------------------------------------------------------------
|
| Public storefront + payment webhooks need no session. Everything the learner
| owns sits behind auth:sanctum. Admin capabilities sit behind auth:sanctum plus
| a can:<permission> gate whose string comes from the CommercePermission enum.
| Payment webhooks are rate-limited (throttle:commerce-webhook) and verify their
| signature INSIDE the gateway adapter, so they fail closed.
*/

// --- Public storefront + payment webhooks -------------------------------------

Route::prefix('v1')->group(function (): void {
    Route::get('products', [ProductController::class, 'index'])->name('commerce.products.index');
    Route::get('products/{publicId}', [ProductController::class, 'show'])->name('commerce.products.show');

    Route::post('payment/webhook', [PaymentWebhookController::class, 'handle'])
        ->middleware('throttle:commerce-webhook')
        ->name('commerce.payment.webhook');

    Route::post('payment/webhook/{provider}', [PaymentWebhookController::class, 'handleProvider'])
        ->middleware('throttle:commerce-webhook')
        ->name('commerce.payment.webhook.provider');

    Route::post('coupons/validate', [CouponController::class, 'validate'])
        ->middleware('throttle:commerce-coupon')
        ->name('commerce.coupons.validate');

    // --- Authenticated learner endpoints ------------------------------------------

    Route::middleware('auth:sanctum')->group(function (): void {

        // Cart
        Route::get('cart', [CartController::class, 'show'])->name('commerce.cart.show');
        Route::post('cart', [CartController::class, 'store'])->name('commerce.cart.store');
        Route::delete('cart/items/{product}', [CartController::class, 'removeItem'])->name('commerce.cart.items.destroy');
        Route::delete('cart', [CartController::class, 'clear'])->name('commerce.cart.clear');

        // Checkout
        Route::post('checkout', [CheckoutController::class, 'store'])
            ->middleware('throttle:commerce-checkout')
            ->name('commerce.checkout');

        // Orders
        Route::get('orders', [OrderController::class, 'index'])->name('commerce.orders.index');
        Route::get('orders/{order}', [OrderController::class, 'show'])->name('commerce.orders.show');

        // Invoices (billing portal)
        Route::get('invoices', [InvoiceController::class, 'index'])->name('commerce.invoices.index');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('commerce.invoices.show');

        // Contracts
        Route::get('contracts', [ContractController::class, 'index'])->name('commerce.contracts.index');
        Route::post('contracts/{contract}/accept', [ContractController::class, 'accept'])->name('commerce.contracts.accept');

        // Entitlements
        Route::get('entitlements', [EntitlementController::class, 'index'])->name('commerce.entitlements.index');

        // Subscription plan catalogue
        Route::get('subscription-plans', [SubscriptionPlanController::class, 'index'])->name('commerce.subscription-plans.index');

        // Subscriptions
        Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('commerce.subscriptions.index');
        Route::post('subscriptions', [SubscriptionController::class, 'store'])->name('commerce.subscriptions.store');
        Route::post('subscriptions/{subscription}/cancel', [SubscriptionController::class, 'cancel'])->name('commerce.subscriptions.cancel');
        Route::post('subscriptions/{subscription}/reactivate', [SubscriptionController::class, 'reactivate'])->name('commerce.subscriptions.reactivate');
        Route::post('subscriptions/{subscription}/change', [SubscriptionController::class, 'change'])->name('commerce.subscriptions.change');

        // --- Admin (auth + capability gate) ---------------------------------------

        // Read-side admin views gate on the orders.view capability.
        Route::middleware('can:'.CommercePermission::ViewOrders->value)->group(function (): void {
            Route::get('admin/orders', [AdminOrderController::class, 'index'])->name('commerce.admin.orders.index');
            Route::get('admin/subscriptions', [AdminSubscriptionController::class, 'index'])->name('commerce.admin.subscriptions.index');
            Route::get('admin/credit-notes', [CreditNoteController::class, 'index'])->name('commerce.admin.credit-notes.index');
            Route::get('admin/analytics', [AdminCommerceAnalyticsController::class, 'index'])->name('commerce.admin.analytics.index');
        });

        // Issuing a refund is a privileged write.
        Route::post('admin/orders/{order}/refund', [RefundController::class, 'refund'])
            ->middleware('can:'.CommercePermission::ManageRefunds->value)
            ->name('commerce.admin.orders.refund');

        // Coupon management.
        Route::middleware('can:'.CommercePermission::ManageCoupons->value)->group(function (): void {
            Route::get('admin/coupons', [AdminCouponController::class, 'index'])->name('commerce.admin.coupons.index');
            Route::post('admin/coupons', [AdminCouponController::class, 'store'])->name('commerce.admin.coupons.store');
            Route::patch('admin/coupons/{coupon}', [AdminCouponController::class, 'update'])->name('commerce.admin.coupons.update');
        });
    });
});
