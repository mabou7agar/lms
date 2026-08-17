<?php

namespace App\Contexts\Commerce\Services;

use App\Contexts\Commerce\Enums\BuyerType;
use App\Contexts\Commerce\Exceptions\BuyerAudienceMismatchException;
use App\Contexts\Commerce\Exceptions\ProductUnavailableException;
use App\Contexts\Commerce\Models\Cart;
use App\Contexts\Commerce\Models\CartItem;
use App\Contexts\Commerce\Models\Product;
use App\Platform\Shared\Analytics\AnalyticsEventName;
use App\Platform\Shared\Analytics\Contracts\AnalyticsEventRecorder;
use App\Platform\Shared\Analytics\Data\AnalyticsEventInput;
use App\Platform\Shared\Services\BaseService;

/**
 * Manages the per-user cart and computes totals (subtotal, discount, total) in minor units.
 */
class CartService extends BaseService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly CouponService $coupons,
        private readonly AnalyticsEventRecorder $analytics,
        private readonly SeatPurchaseService $seats,
    ) {}

    public function currentByUserId(int $userId): Cart
    {
        return Cart::firstOrCreate(
            ['user_id' => $userId],
            ['currency' => (string) config('commerce.default_currency')],
        );
    }

    /**
     * @param  int|null  $seats  the seat count the buyer chose, for a product sold by the seat.
     */
    public function addProduct(Cart $cart, Product $product, ?int $seats = null): CartItem
    {
        if (! $product->isActive()) {
            throw new ProductUnavailableException;
        }

        // The audience rule is enforced here rather than only in the UI: this is the single path by
        // which anything enters a cart, so a company-only licence cannot be added by an individual
        // (or the reverse) by calling the API directly.
        $this->assertAudienceAllows($cart, $product);
        $quantity = $this->seats->resolveQuantity($product, $cart->buyerType(), $seats);

        $amount = $this->pricing->effectiveMinor($product, $cart->currency);

        if ($amount === null) {
            throw new ProductUnavailableException('No price is set for this product in your currency.');
        }

        $item = CartItem::updateOrCreate(
            ['cart_id' => $cart->id, 'product_id' => $product->id],
            ['unit_amount_minor' => $amount, 'quantity' => $quantity],
        );

        // The top of the purchase funnel. Keyed per (cart, product) so re-adding the same product
        // to the same cart is the one intent it actually is, not two.
        $this->analytics->record(new AnalyticsEventInput(
            name: AnalyticsEventName::CartItemAdded->value,
            userId: (int) $cart->user_id,
            organizationId: $cart->organization_id === null ? null : (int) $cart->organization_id,
            productId: (int) $product->id,
            productType: $product->type->value,
            buyerType: $cart->buyerType()->value,
            valueMinor: $this->seats->lineAmountMinor($product, $amount, $quantity),
            dedupKey: 'cart_item_added:'.$cart->id.':'.$product->id,
        ));

        return $item;
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
     * Re-check a line that is already in the cart against the product as it stands now.
     *
     * An admin can change the seat mode, the bounds or the pricing basis while a cart sits idle, so
     * the count captured when the item was added may no longer be one this product is sold in.
     * Checked at checkout as well as here, because that is the last moment before money moves.
     */
    public function assertLineStillSellable(Cart $cart, CartItem $item, Product $product): void
    {
        $this->seats->resolveQuantity($product, $cart->buyerType(), $item->seatSelection());
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

        // A line's amount is the unit price times its seats for a per-seat product, and the unit
        // price alone for everything else — so a coupon discounts what the buyer actually pays.
        $subtotal = (int) $cart->items->sum(fn (CartItem $i): int => $this->lineAmount($i));

        $discount = 0;
        if ($cart->coupon !== null) {
            $lines = $cart->items->map(fn (CartItem $i) => [
                'product_id' => $i->product_id,
                'amount_minor' => $this->lineAmount($i),
            ]);
            $discount = $this->coupons->discountMinor($cart->coupon, $lines);
        }

        return [
            'subtotal_minor' => $subtotal,
            'discount_minor' => $discount,
            'total_minor' => max(0, $subtotal - $discount),
        ];
    }

    /** The charged amount for one cart line. */
    public function lineAmount(CartItem $item): int
    {
        $product = $item->relationLoaded('product') ? $item->getRelation('product') : $item->product;

        return $product instanceof Product
            ? $this->seats->lineAmountMinor($product, (int) $item->unit_amount_minor, $item->quantityOrOne())
            : (int) $item->unit_amount_minor;
    }
}
