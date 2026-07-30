<?php

namespace App\Contexts\Commerce\Http\Controllers\Api\V1;

use App\Contexts\Commerce\Http\Resources\OrderDetailResource;
use App\Contexts\Commerce\Models\Order;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Admin order list. Thin read endpoint: paginate every order (optionally filtered by status) for
 * back-office review. Authorization is enforced by the can:commerce.orders.view route gate. No
 * persistence or business logic lives here.
 */
class AdminOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));

        $orders = Order::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->string('status')))
            ->with(['items', 'invoice'])
            ->latest('id')
            ->paginate($perPage);

        return ApiResponse::paginated($orders, OrderDetailResource::class);
    }
}
