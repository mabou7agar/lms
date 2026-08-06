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
            'items' => $cart->items->map(fn ($item) => [
                'id' => $item->public_id,
                'product_id' => $item->product->public_id,
                'title' => $item->product->localized('title'),
                'unit_amount_minor' => $item->unit_amount_minor,
            ])->values(),
            'subtotal_minor' => $totals['subtotal_minor'],
            'discount_minor' => $totals['discount_minor'],
            'total_minor' => $totals['total_minor'],
        ];
    }
}
