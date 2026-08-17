<?php

namespace App\Contexts\Commerce\Http\Controllers\Api\V1;

use App\Contexts\Commerce\Actions\Cart\AddToCartAction;
use App\Contexts\Commerce\Actions\Cart\ApplyCouponAction;
use App\Contexts\Commerce\Actions\Cart\ClearCartAction;
use App\Contexts\Commerce\Actions\Cart\RemoveFromCartAction;
use App\Contexts\Commerce\Actions\Cart\SetCartBuyerAction;
use App\Contexts\Commerce\Enums\BuyerType;
use App\Contexts\Commerce\Http\Requests\AddToCartRequest;
use App\Contexts\Commerce\Http\Requests\SetCartBuyerRequest;
use App\Contexts\Commerce\Http\Resources\CartResource;
use App\Contexts\Commerce\Models\Product;
use App\Contexts\Commerce\Services\CartService;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CartController extends Controller
{
    public function show(Request $request, CartService $carts): JsonResponse
    {
        $cart = $carts->currentByUserId($request->user()->id)->load(['items.product', 'coupon']);

        return ApiResponse::success(new CartResource(['cart' => $cart, 'totals' => $carts->totals($cart)]));
    }

    public function store(AddToCartRequest $request, AddToCartAction $add, ApplyCouponAction $applyCoupon, CartService $carts): JsonResponse
    {
        $data = $request->validated();

        $product = Product::where('public_id', $data['product'])->first();
        if ($product === null) {
            throw new NotFoundHttpException('Product not found.');
        }

        $add->executeByUserId(
            $request->user()->id,
            $product,
            isset($data['seats']) ? (int) $data['seats'] : null,
        );

        if (! empty($data['coupon_code'])) {
            $applyCoupon->executeByUserId($request->user()->id, $data['coupon_code']);
        }

        $cart = $carts->currentByUserId($request->user()->id)->load(['items.product', 'coupon']);

        return ApiResponse::success(new CartResource(['cart' => $cart, 'totals' => $carts->totals($cart)]), 'Cart updated.');
    }

    /**
     * Switch the cart between an individual and a company purchase. The organization is resolved
     * from the caller's own membership inside the action — never taken from the request.
     */
    public function setBuyer(SetCartBuyerRequest $request, SetCartBuyerAction $action, CartService $carts): JsonResponse
    {
        $action->executeByUserId(
            $request->user()->id,
            BuyerType::from($request->validated()['buyer_type']),
        );

        $cart = $carts->currentByUserId($request->user()->id)->load(['items.product', 'coupon']);

        return ApiResponse::success(new CartResource(['cart' => $cart, 'totals' => $carts->totals($cart)]), 'Buyer updated.');
    }

    public function removeItem(string $product, Request $request, RemoveFromCartAction $remove, CartService $carts): JsonResponse
    {
        $model = Product::where('public_id', $product)->first();
        if ($model === null) {
            throw new NotFoundHttpException('Product not found.');
        }

        $remove->executeByUserId($request->user()->id, $model);

        $cart = $carts->currentByUserId($request->user()->id)->load(['items.product', 'coupon']);

        return ApiResponse::success(new CartResource(['cart' => $cart, 'totals' => $carts->totals($cart)]), 'Item removed.');
    }

    public function destroy(Request $request, ClearCartAction $clear): JsonResponse
    {
        $clear->executeByUserId($request->user()->id);

        return ApiResponse::deleted('Cart cleared.');
    }
}
