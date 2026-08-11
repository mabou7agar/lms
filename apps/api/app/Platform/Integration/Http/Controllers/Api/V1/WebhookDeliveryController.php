<?php

declare(strict_types=1);

namespace App\Platform\Integration\Http\Controllers\Api\V1;

use App\Platform\Integration\Http\Resources\WebhookDeliveryResource;
use App\Platform\Integration\Models\WebhookDelivery;
use App\Platform\Integration\Models\WebhookEndpoint;
use App\Platform\Integration\Services\WebhookEndpointService;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Read + replay of an endpoint's deliveries. {endpoint} is tenant-bound (BelongsToTenant scope) and
 * {delivery} is resolved through the endpoint's deliveries() relation via scoped route binding, so a
 * delivery from another org — or from another endpoint — can never be listed or replayed here.
 */
class WebhookDeliveryController extends Controller
{
    public function __construct(private readonly WebhookEndpointService $service) {}

    public function index(Request $request, WebhookEndpoint $endpoint): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);

        $paginator = $endpoint->deliveries()->latest('id')->paginate($perPage);

        return ApiResponse::paginated($paginator, WebhookDeliveryResource::class);
    }

    public function replay(WebhookEndpoint $endpoint, WebhookDelivery $delivery): JsonResponse
    {
        $replay = $this->service->replay($delivery);

        return ApiResponse::created(
            (new WebhookDeliveryResource($replay))->resolve(),
            'Delivery re-queued.',
        );
    }
}
