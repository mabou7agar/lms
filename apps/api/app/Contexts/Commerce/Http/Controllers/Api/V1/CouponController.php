<?php

namespace App\Contexts\Commerce\Http\Controllers\Api\V1;

use App\Contexts\Commerce\Actions\Coupon\ValidateCouponAction;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Storefront coupon endpoints. Thin: it validates the request shape and delegates to
 * ValidateCouponAction. All validity/limit/eligibility rules and the discount preview are
 * server-authoritative and live in the domain layer — there is no business logic or persistence
 * here. Money is integer minor units.
 */
class CouponController extends Controller
{
    public function validate(Request $request, ValidateCouponAction $action): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'subtotal_minor' => ['required', 'integer', 'min:0'],
        ]);

        $result = $action->execute(
            (string) $validated['code'],
            (int) ($request->user()?->getAuthIdentifier() ?? 0),
            (int) $validated['subtotal_minor'],
        );

        return ApiResponse::success($result);
    }
}
