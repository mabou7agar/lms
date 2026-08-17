<?php

namespace App\Contexts\Commerce\Http\Resources;

use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Wrap with totals: new CartResource(['cart' => $cart, 'totals' => [...]]).
 */
class CartResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        $cart = $this->resource['cart'];
        $totals = $this->resource['totals'];

        return [
            'id' => $cart->public_id,
            'currency' => $cart->currency,
            'coupon' => $cart->coupon?->code,
            // Who this cart is being bought by. The UI shows it and offers the switch; the server
            // decides whether the switch is allowed.
            'buyer_type' => $cart->buyerType()->value,
            'organization_id' => $cart->organization_id,
            'items' => $cart->items->map(fn ($item) => [
                'id' => $item->public_id,
                'product_id' => $item->product->public_id,
                'title' => $item->product->localized('title'),
                'unit_amount_minor' => $item->unit_amount_minor,
                // Seats and the resulting line amount are both sent: the cart has to show the buyer
                // "25 × SAR 400 = SAR 10,000", and computing the product of those in the browser is
                // how a UI ends up disagreeing with the invoice.
                'quantity' => $item->quantityOrOne(),
                'line_amount_minor' => $item->product->lineAmountMinor(
                    (int) $item->unit_amount_minor,
                    $item->quantityOrOne(),
                ),
            ])->values(),
            'subtotal_minor' => $totals['subtotal_minor'],
            'discount_minor' => $totals['discount_minor'],
            'total_minor' => $totals['total_minor'],
        ];
    }
}
