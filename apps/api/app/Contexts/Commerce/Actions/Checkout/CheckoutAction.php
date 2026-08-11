<?php

namespace App\Contexts\Commerce\Actions\Checkout;

use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Contracts\TaxCalculator;
use App\Contexts\Commerce\Enums\InvoiceStatus;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\TransactionStatus;
use App\Contexts\Commerce\Enums\TransactionType;
use App\Contexts\Commerce\Events\OrderPlaced;
use App\Contexts\Commerce\Exceptions\CartEmptyException;
use App\Contexts\Commerce\Exceptions\CheckoutInProgressException;
use App\Contexts\Commerce\Exceptions\CouponExhaustedException;
use App\Contexts\Commerce\Exceptions\CouponExpiredException;
use App\Contexts\Commerce\Exceptions\CouponInvalidException;
use App\Contexts\Commerce\Models\Coupon;
use App\Contexts\Commerce\Models\CouponRedemption;
use App\Contexts\Commerce\Models\Invoice;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\OrderItem;
use App\Contexts\Commerce\Models\PaymentTransaction;
use App\Contexts\Commerce\Payments\Data\ChargeRequest;
use App\Contexts\Commerce\Services\CartService;
use App\Contexts\Commerce\Services\ContractService;
use App\Contexts\Commerce\Services\CouponService;
use App\Contexts\Commerce\Services\InvoiceNumberService;
use App\Platform\Shared\Actions\BaseAction;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Converts the cart into an order + invoice + a pending contract, records coupon redemption
 * under a lock, then initiates payment via the gateway abstraction. Confirmation arrives by
 * webhook. Enrollment is NOT granted here — only after payment + contract acceptance.
 *
 * The order (pending) + coupon redemption COMMIT before any gateway I/O, so no network call
 * ever runs inside a DB transaction. If the gateway call fails, a compensating transaction
 * marks the order failed and releases the coupon redemption. The order public_id doubles as
 * the gateway idempotency key so provider-side retries cannot double-charge.
 *
 * Tax is server-authoritative: it is computed via the TaxCalculator port on the DISCOUNTED
 * base (never trusted from the client) and folded into the order/invoice grand total, which is
 * what the gateway is asked to charge.
 *
 * @phpstan-type CheckoutResult array{order: Order, contract: ?\App\Contexts\Commerce\Models\Contract, charge: \App\Contexts\Commerce\Payments\Data\ChargeResult}
 */
class CheckoutAction extends BaseAction
{
    public function __construct(
        private readonly CartService $carts,
        private readonly ContractService $contracts,
        private readonly InvoiceNumberService $invoiceNumbers,
        private readonly PaymentGateway $gateway,
        private readonly TaxCalculator $tax,
        private readonly CouponService $coupons,
    ) {}

    /**
     * Serialize checkout per user so a duplicate submit (double-click or concurrent request)
     * cannot create a second order plus a second gateway charge from the same cart. The lock is
     * held across the whole flow — including the gateway call — and the first request empties the
     * cart before releasing, so a queued duplicate re-reads an empty cart and is rejected with
     * CartEmptyException (never a second charge). A caller that cannot acquire the lock within the
     * block window gets a 409 CheckoutInProgressException instead of proceeding.
     *
     * @return array{order: Order, contract: mixed, charge: mixed}
     */
    public function executeByUserId(int $userId): array
    {
        $lock = Cache::lock("commerce:checkout:user:{$userId}", 30);

        try {
            return $lock->block(5, fn (): array => $this->run($userId));
        } catch (LockTimeoutException) {
            throw new CheckoutInProgressException;
        }
    }

    /** @return array{order: Order, contract: mixed, charge: mixed} */
    private function run(int $userId): array
    {
        $cart = $this->carts->currentByUserId($userId)->load(['items.product', 'coupon']);

        if ($cart->items->isEmpty()) {
            throw new CartEmptyException;
        }

        // Phase 1: create the order, invoice, coupon redemption, and contract; COMMIT first.
        [$order, $contract] = $this->transaction(function () use ($userId, $cart): array {
            // Lock the coupon row to serialize redemption counting; re-load as a typed Coupon.
            $cartCoupon = $cart->coupon;
            $coupon = null;
            if ($cartCoupon !== null) {
                $coupon = Coupon::query()->whereKey($cartCoupon->getKey())->lockForUpdate()->first();

                // Re-validate under the lock: since the coupon was applied to the cart it may have been
                // deactivated or moved out of its validity window (a persisted cart.coupon_id would
                // otherwise still discount at checkout — a revenue leak), or its counter may have moved.
                if ($coupon === null || ! $coupon->is_active) {
                    throw new CouponInvalidException;
                }
                if (! $coupon->isWithinWindow()) {
                    throw new CouponExpiredException;
                }
                if ($coupon->isExhausted()) {
                    throw new CouponExhaustedException;
                }

                // Also re-assert the per-user rules under the lock (first-order-only, per-user cap):
                // apply-time validation is racy, so this is where the authoritative check happens.
                $this->coupons->assertPromotionRulesForUser($coupon, $userId);
            }

            $totals = $this->carts->totals($cart);

            // Server-authoritative tax. The taxable base is the DISCOUNTED subtotal, passed as a
            // single line so the base is exactly max(0, subtotal - discount) with no per-line
            // rounding drift. Jurisdiction + inclusive flag come from config, never the client.
            $tax = $this->tax->calculate(
                $cart->getAttribute('currency'),
                (string) config('commerce.tax.default_country'),
                [$totals['total_minor']],
                (bool) config('commerce.tax.prices_include_tax', false),
            );

            $order = Order::create([
                'user_id' => $userId,
                'status' => OrderStatus::Pending->value,
                'currency' => $cart->getAttribute('currency'),
                'subtotal_minor' => $totals['subtotal_minor'],
                'discount_minor' => $totals['discount_minor'],
                'tax_minor' => $tax->taxMinor,
                // Grand total is tax-inclusive: the discounted subtotal plus tax when prices are
                // tax-exclusive (the default), or the tax-inclusive base itself otherwise. In both
                // cases this equals the gross of the taxable base.
                'total_minor' => $tax->grossMinor,
                'coupon_id' => $coupon?->getKey(),
                'placed_at' => now(),
            ]);

            foreach ($cart->items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'title' => $item->product->title,
                    'unit_amount_minor' => $item->unit_amount_minor,
                ]);
            }

            Invoice::create([
                'order_id' => $order->id,
                'number' => $this->invoiceNumbers->next(),
                'status' => InvoiceStatus::Issued->value,
                'currency' => $order->currency,
                // Invoice reconciles as net + tax = total: subtotal is the net (pre-tax) taxable
                // base, tax is the VAT, total is the tax-inclusive grand total.
                'subtotal_minor' => $tax->netMinor,
                'tax_minor' => $tax->taxMinor,
                'total_minor' => $order->total_minor,
                'issued_at' => now(),
            ]);

            if ($coupon !== null) {
                $coupon->increment('redeemed_count');
                $coupon->redemptions()->create(['user_id' => $userId, 'order_id' => $order->id]);
            }

            $contract = $this->contracts->createForOrderByUserId($userId, $order);

            return [$order, $contract];
        });

        // Phase 2: gateway charge OUTSIDE any DB transaction. Amount is the tax-inclusive total.
        try {
            $charge = $this->gateway->charge(new ChargeRequest(
                reference: $order->public_id,
                amountMinor: $order->total_minor,
                currency: $order->currency,
                description: 'HElbaron order '.$order->public_id,
                idempotencyKey: $order->public_id,
            ));
        } catch (Throwable $e) {
            $this->compensate($order);

            throw $e;
        }

        // Phase 3: record the pending transaction, then empty the captured cart.
        $this->transaction(function () use ($order, $cart, $charge): void {
            PaymentTransaction::create([
                'order_id' => $order->id,
                'provider' => (string) config('commerce.payment.provider'),
                'provider_reference' => $charge->providerReference,
                'type' => TransactionType::Charge->value,
                'status' => TransactionStatus::Pending->value,
                'amount_minor' => $order->total_minor,
                'currency' => $order->currency,
            ]);

            // Empty the cart now that it is captured on the order.
            $this->carts->clear($cart);
        });

        OrderPlaced::dispatch($order);

        return ['order' => $order, 'contract' => $contract, 'charge' => $charge];
    }

    /** Compensating action: mark the order failed and release the coupon redemption. */
    private function compensate(Order $order): void
    {
        $this->transaction(function () use ($order): void {
            $order->forceFill(['status' => OrderStatus::Failed->value])->save();

            if ($order->coupon_id === null) {
                return;
            }

            $coupon = Coupon::whereKey($order->coupon_id)->lockForUpdate()->first();

            if ($coupon !== null && $coupon->redeemed_count > 0) {
                $coupon->decrement('redeemed_count');
            }

            CouponRedemption::where('order_id', $order->id)->delete();
        });
    }
}
