<?php

declare(strict_types=1);

namespace App\Platform\Integration\Http\Controllers\Api\V1;

use App\Platform\Integration\Exceptions\WebhookUrlNotAllowedException;
use App\Platform\Integration\Http\Requests\StoreWebhookEndpointRequest;
use App\Platform\Integration\Http\Requests\UpdateWebhookEndpointRequest;
use App\Platform\Integration\Http\Resources\WebhookEndpointResource;
use App\Platform\Integration\Models\WebhookEndpoint;
use App\Platform\Integration\Security\WebhookUrlGuard;
use App\Platform\Integration\Services\WebhookEndpointService;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Authenticated management of a tenant's outbound webhook endpoints. Every query rides the model's
 * BelongsToTenant global scope, so a caller only ever sees/binds their own org's endpoints — another
 * org's public_id simply 404s (no existence leak). Mutations delegate to WebhookEndpointService.
 */
class WebhookEndpointController extends Controller
{
    public function __construct(
        private readonly WebhookEndpointService $service,
        private readonly WebhookUrlGuard $guard,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->integer('per_page', 20), 1), 100);

        $paginator = WebhookEndpoint::query()->latest('id')->paginate($perPage);

        return ApiResponse::paginated($paginator, WebhookEndpointResource::class);
    }

    public function store(StoreWebhookEndpointRequest $request): JsonResponse
    {
        /** @var array{name: string, description?: string|null, url: string, event_types: array<int, string>} $data */
        $data = $request->validated();

        if (($rejection = $this->rejectUnsafeUrl($data['url'])) !== null) {
            return $rejection;
        }

        $endpoint = $this->service->create($data, $this->actorId());

        // The plaintext secret is revealed EXACTLY ONCE, here.
        return ApiResponse::created([
            'endpoint' => (new WebhookEndpointResource($endpoint))->resolve(),
            'secret' => $endpoint->secret,
        ], 'Webhook endpoint created. Store the secret now — it will not be shown again.');
    }

    public function show(WebhookEndpoint $endpoint): JsonResponse
    {
        return ApiResponse::success((new WebhookEndpointResource($endpoint))->resolve());
    }

    public function update(UpdateWebhookEndpointRequest $request, WebhookEndpoint $endpoint): JsonResponse
    {
        /** @var array{name?: string, description?: string|null, url?: string, event_types?: array<int, string>} $data */
        $data = $request->validated();

        if (isset($data['url']) && ($rejection = $this->rejectUnsafeUrl($data['url'])) !== null) {
            return $rejection;
        }

        $endpoint = $this->service->update($endpoint, $data);

        return ApiResponse::updated((new WebhookEndpointResource($endpoint))->resolve());
    }

    public function rotateSecret(WebhookEndpoint $endpoint): JsonResponse
    {
        $secret = $this->service->rotateSecret($endpoint);

        return ApiResponse::success(
            ['secret' => $secret],
            'Secret rotated. Store it now — it will not be shown again.',
        );
    }

    public function enable(WebhookEndpoint $endpoint): JsonResponse
    {
        $this->service->setActive($endpoint, true);

        return ApiResponse::updated((new WebhookEndpointResource($endpoint))->resolve());
    }

    public function disable(WebhookEndpoint $endpoint): JsonResponse
    {
        $this->service->setActive($endpoint, false);

        return ApiResponse::updated((new WebhookEndpointResource($endpoint))->resolve());
    }

    /** SSRF/transport pre-check; returns a 422 response when rejected, else null. */
    private function rejectUnsafeUrl(string $url): ?JsonResponse
    {
        try {
            $this->guard->assertAllowed($url);

            return null;
        } catch (WebhookUrlNotAllowedException $e) {
            return ApiResponse::error(
                'WEBHOOK_URL_REJECTED',
                'The destination URL is not allowed.',
                ['reason' => $e->getMessage()],
                422,
            );
        }
    }

    private function actorId(): ?int
    {
        $id = auth()->id();

        return $id !== null ? (int) $id : null;
    }
}
