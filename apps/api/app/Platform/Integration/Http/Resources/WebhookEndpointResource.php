<?php

declare(strict_types=1);

namespace App\Platform\Integration\Http\Resources;

use App\Platform\Integration\Models\WebhookEndpoint;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Client-safe view of an endpoint. Exposes the public id and configuration but NEVER the signing
 * secret (which is $hidden on the model and only ever returned once, out-of-band, on create/rotate).
 *
 * @property WebhookEndpoint $resource
 */
class WebhookEndpointResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'name' => $this->resource->name,
            'description' => $this->resource->description,
            'url' => $this->resource->url,
            'event_types' => $this->resource->event_types,
            'active' => $this->resource->active,
            'consecutive_failures' => $this->resource->consecutive_failures,
            'disabled_at' => $this->resource->disabled_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
