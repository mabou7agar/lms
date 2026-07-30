<?php

namespace App\Contexts\Commerce\Http\Controllers\Api\V1;

use App\Contexts\Commerce\Http\Resources\SubscriptionResource;
use App\Contexts\Commerce\Models\Subscription;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Admin subscription list. Thin read endpoint: paginate every subscription (optionally filtered by
 * status) for back-office review. Authorization is enforced by the can:commerce.orders.view route
 * gate. No persistence or business logic lives here.
 */
class AdminSubscriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = max(1, min((int) $request->integer('per_page', 20), 100));

        $subscriptions = Subscription::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->string('status')))
            ->with(['plan'])
            ->latest('id')
            ->paginate($perPage);

        return ApiResponse::paginated($subscriptions, SubscriptionResource::class);
    }
}
