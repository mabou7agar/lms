<?php

namespace App\Contexts\Commerce\Http\Controllers\Api\V1;

use App\Contexts\Commerce\Actions\Coupon\CreateCouponAction;
use App\Contexts\Commerce\Actions\Coupon\ListCouponsAction;
use App\Contexts\Commerce\Actions\Coupon\UpdateCouponAction;
use App\Contexts\Commerce\Enums\CouponScope;
use App\Contexts\Commerce\Enums\CouponType;
use App\Contexts\Commerce\Http\Resources\CouponResource;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Admin coupon management. Thin: validate input, delegate writes to domain Actions (persistence
 * never lives in the controller), and shape output through CouponResource. Authorization is enforced
 * by the can:commerce.coupons.manage route gate.
 */
class AdminCouponController extends Controller
{
    public function index(Request $request, ListCouponsAction $action): JsonResponse
    {
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));

        return ApiResponse::paginated($action->execute($perPage), CouponResource::class);
    }

    public function store(Request $request, CreateCouponAction $action): JsonResponse
    {
        $data = $this->validated($request);

        return ApiResponse::success(new CouponResource($action->execute($data)), null, 201);
    }

    public function update(Request $request, string $coupon, UpdateCouponAction $action): JsonResponse
    {
        $data = $this->validated($request, false);

        return ApiResponse::success(new CouponResource($action->execute($coupon, $data)));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating = true): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'code' => [$required, 'string', 'max:64'],
            'type' => [$required, Rule::in(CouponType::values())],
            'value' => [$required, 'integer', 'min:0'],
            'scope' => [$required, Rule::in(CouponScope::values())],
            'currency' => ['nullable', 'string', 'size:3'],
            'max_redemptions' => ['nullable', 'integer', 'min:0'],
            'per_user_limit' => ['nullable', 'integer', 'min:1'],
            'first_order_only' => ['boolean'],
            'min_subtotal_minor' => ['nullable', 'integer', 'min:0'],
            'stackable' => ['boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['boolean'],
        ]);
    }
}
