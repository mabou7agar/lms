<?php

namespace App\Contexts\Commerce\Services;

use App\Contexts\Commerce\Enums\BuyerType;
use App\Contexts\Commerce\Exceptions\BuyerAudienceMismatchException;
use App\Contexts\Commerce\Exceptions\ProductUnavailableException;
use App\Contexts\Commerce\Models\Cart;
use App\Contexts\Commerce\Models\CartItem;
use App\Contexts\Commerce\Models\Product;
use App\Platform\Shared\Services\BaseService;

/**
 * Manages the per-user cart and computes totals (subtotal, discount, total) in minor units.
 */
class CartService extends BaseService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly CouponService $coupons,
    ) {}

    public function currentByUserId(int $userId): Cart
    {
        return Cart::firstOrCreate(
            ['user_id' => $userId],
            ['currency' => (string) config('commerce.default_currency')],
        );
    }

    public function addProduct(Cart $cart, Product $product): CartItem
    {
        if (! $product->isActive()) {
            throw new ProductUnavailableException;
        }

        // The audience rule is enforced here rather than only in the UI: this is the single path by
        // which anything enters a cart, so a company-only licence cannot be added by an individual
        // (or the reverse) by calling the API directly.
        $this->assertAudienceAllows($cart, $product);

        $amount = $this->pricing->effectiveMinor($product, $cart->currency);

        if ($amount === null) {
            throw new ProductUnavailableException('No price is set for this product in your currency.');
        }

        return CartItem::updateOrCreate(
            ['cart_id' => $cart->id, 'product_id' => $product->id],
            ['unit_amount_minor' => $amount],
        );
    }

    /**
     * Refuse a product this cart's buyer is not sold to.
     *
     * A product with no audience recorded (created before the commercial-policy wave) is treated as
     * sold to everyone, so an older catalogue keeps working instead of becoming unbuyable.
     */
    public function assertAudienceAllows(Cart $cart, Product $product): void
    {
        $audience = $product->audience;

        if ($audience === null) {
            return;
        }

        $allowed = $cart->buyerType()->isCompany()
            ? $audience->allowsCompany()
            : $audience->allowsIndividual();

        if (! $allowed) {
            throw new BuyerAudienceMismatchException(
                $cart->buyerType()->isCompany()
                    ? 'This product is sold to individuals only.'
                    : 'This product is sold to companies only. Switch to a company purchase to buy it.',
            );
        }
    }

    /**
     * Whether every item already in the cart may be bought by the given buyer type. Used before
     * switching a cart between individual and company so the switch cannot strand an item.
     */
    public function isCompatibleWithBuyerType(Cart $cart, BuyerType $buyerType): bool
    {
        foreach ($cart->items()->with('product')->get() as $item) {
            $product = $item->getRelation('product');
            if (! $product instanceof Product) {
                continue;
            }

            $audience = $product->audience;
            if ($audience === null) {
                continue;
            }
            $allowed = $buyerType->isCompany() ? $audience->allowsCompany() : $audience->allowsIndividual();
            if (! $allowed) {
                return false;
            }
        }

        return true;
    }

    public function removeProduct(Cart $cart, Product $product): void
    {
        $cart->items()->where('product_id', $product->id)->delete();
    }

    public function clear(Cart $cart): void
    {
        $cart->items()->delete();
        $cart->forceFill(['coupon_id' => null])->save();
    }

    /** @return array{subtotal_minor: int, discount_minor: int, total_minor: int} */
    public function totals(Cart $cart): array
    {
        $cart->loadMissing('items', 'coupon');

        $subtotal = (int) $cart->items->sum('unit_amount_minor');

        $discount = 0;
        if ($cart->coupon !== null) {
            $lines = $cart->items->map(fn (CartItem $i) => [
                'product_id' => $i->product_id,
                'amount_minor' => $i->unit_amount_minor,
            ]);
            $discount = $this->coupons->discountMinor($cart->coupon, $lines);
        }

        return [
            'subtotal_minor' => $subtotal,
            'discount_minor' => $discount,
            'total_minor' => max(0, $subtotal - $discount),
        ];
    }
}
