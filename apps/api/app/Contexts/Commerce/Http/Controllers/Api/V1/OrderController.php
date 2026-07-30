<?php

namespace App\Contexts\Commerce\Http\Controllers\Api\V1;

use App\Contexts\Commerce\Actions\Order\GetOrderForUserAction;
use App\Contexts\Commerce\Http\Resources\OrderDetailResource;
use App\Contexts\Commerce\Models\Order;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Learner order endpoints. Thin: resolve the authenticated user, then either paginate the user's
 * own orders or resolve a single one by public id. Ownership is enforced by scoping every query to
 * the authenticated user's id, so a caller can never read another user's order. The {order} param
 * is a string (public_id) — there is NO implicit route-model binding; the read Action resolves it.
 * No persistence or business logic lives here.
 */
class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->integer('per_page', 15), 100));

        $orders = Order::query()
            ->where('user_id', $this->userId($request))
            ->with(['items', 'invoice'])
            ->latest('id')
            ->paginate($perPage);

        return ApiResponse::success(OrderDetailResource::collection($orders));
    }

    public function show(Request $request, string $order, GetOrderForUserAction $action): JsonResponse
    {
        $found = $action->execute($this->userId($request), $order);

        return ApiResponse::success((new OrderDetailResource($found))->resolve());
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()->getAuthIdentifier();
    }
}
