<?php

namespace App\Contexts\Commerce\Http\Controllers\Api\V1;

use App\Contexts\Commerce\Http\Resources\SubscriptionPlanResource;
use App\Contexts\Commerce\Models\SubscriptionPlan;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Public catalogue of active subscription plans with their per-currency prices. Thin read-only
 * endpoint: query the active plans, shape them through SubscriptionPlanResource. No business logic,
 * no persistence.
 */
class SubscriptionPlanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $plans = SubscriptionPlan::query()
            ->where('is_active', true)
            ->with('prices')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(SubscriptionPlanResource::collection($plans));
    }
}
