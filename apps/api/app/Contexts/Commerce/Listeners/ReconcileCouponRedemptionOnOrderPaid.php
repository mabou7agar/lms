<?php

namespace App\Contexts\Commerce\Listeners;

use App\Contexts\Commerce\Events\OrderPaid;
use App\Contexts\Commerce\Models\Coupon;
use App\Contexts\Commerce\Models\CouponRedemption;
use Illuminate\Support\Facades\DB;

/**
 * Guarantees a paid coupon order has its redemption recorded exactly once.
 *
 * The normal checkout path records the redemption up front. But if the gateway charge fails at
 * checkout, CheckoutAction::compensate() releases the redemption (freeing the slot so the buyer can
 * retry). If that same order is later paid via the dunning worker, it would otherwise carry the
 * coupon discount with NO redemption row and NO counter increment — escaping max_redemptions,
 * per_user_limit and first_order_only. This listener re-records it on OrderPaid.
 *
 * Idempotent: it no-ops when a redemption already exists for the order (the normal path), so it only
 * repairs the dunning-recovered case. The coupon_redemptions UNIQUE(order_id) index is the backstop.
 */
class ReconcileCouponRedemptionOnOrderPaid
{
    public function handle(OrderPaid $event): void
    {
        $order = $event->order;
        $couponId = $order->getAttribute('coupon_id');

        if ($couponId === null) {
            return;
        }

        DB::transaction(function () use ($order, $couponId): void {
            if (CouponRedemption::where('order_id', $order->getKey())->exists()) {
                return;
            }

            $coupon = Coupon::whereKey($couponId)->lockForUpdate()->first();
            if ($coupon === null) {
                return;
            }

            $coupon->increment('redeemed_count');
            $coupon->redemptions()->create([
                'user_id' => $order->getAttribute('user_id'),
                'order_id' => $order->getKey(),
            ]);
        });
    }
}
