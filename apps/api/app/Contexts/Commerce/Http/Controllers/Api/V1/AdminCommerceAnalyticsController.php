<?php

namespace App\Contexts\Commerce\Http\Controllers\Api\V1;

use App\Contexts\Commerce\Actions\Analytics\GetCommerceAnalyticsAction;
use App\Contexts\Commerce\Http\Resources\CommerceAnalyticsResource;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Admin commerce analytics endpoint. Thin: it validates the optional from/to ISO date window, then
 * delegates to GetCommerceAnalyticsAction and shapes the aggregate through CommerceAnalyticsResource.
 * No persistence or business logic here — every figure is computed read-only in the service. The
 * route is guarded by the commerce.orders.view permission middleware. Money is integer minor units.
 */
class AdminCommerceAnalyticsController extends Controller
{
    public function index(Request $request, GetCommerceAnalyticsAction $action): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $summary = $action->execute(
            $validated['from'] ?? null,
            $validated['to'] ?? null,
        );

        return ApiResponse::success(new CommerceAnalyticsResource($summary));
    }
}
