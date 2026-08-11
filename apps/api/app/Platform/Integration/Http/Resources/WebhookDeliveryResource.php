<?php

declare(strict_types=1);

namespace App\Platform\Integration\Http\Resources;

use App\Platform\Integration\Models\WebhookDelivery;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Client-safe view of a delivery attempt (status, timing, response + the signature that was sent).
 *
 * @property WebhookDelivery $resource
 */
class WebhookDeliveryResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'event_type' => $this->resource->event_type,
            'event_id' => $this->resource->event_id,
            'status' => $this->resource->status,
            'attempts' => $this->resource->attempts,
            'response_status' => $this->resource->response_status,
            'response_ms' => $this->resource->response_ms,
            'error' => $this->resource->error,
            'signature' => $this->resource->signature,
            'payload' => $this->resource->payload,
            'delivered_at' => $this->resource->delivered_at?->toIso8601String(),
            'next_retry_at' => $this->resource->next_retry_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
