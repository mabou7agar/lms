<?php

namespace App\Contexts\Commerce\Actions\Cart;

use App\Contexts\Commerce\Models\Cart;
use App\Contexts\Commerce\Models\Product;
use App\Contexts\Commerce\Services\CartService;
use App\Platform\Shared\Actions\BaseAction;

class AddToCartAction extends BaseAction
{
    public function __construct(private readonly CartService $carts) {}

    /**
     * @param  int|null  $seats  the seat count chosen for a product sold by the seat.
     */
    public function executeByUserId(int $userId, Product $product, ?int $seats = null): Cart
    {
        return $this->transaction(function () use ($userId, $product, $seats): Cart {
            $cart = $this->carts->currentByUserId($userId);
            $this->carts->addProduct($cart, $product, $seats);

            return $cart->fresh(['items', 'coupon']);
        });
    }
}
