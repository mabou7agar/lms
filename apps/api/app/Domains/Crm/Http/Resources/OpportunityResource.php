<?php

namespace App\Domains\Crm\Http\Resources;

use App\Domains\Crm\Models\Opportunity;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @property Opportunity $resource
 */
class OpportunityResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'name' => $this->resource->name,
            'status' => $this->resource->status->value,
            'amount_minor' => $this->resource->amount_minor,
            'currency' => $this->resource->currency,
            'probability' => $this->resource->probability,
            'product_ref' => $this->resource->product_ref,
            'stage' => $this->whenLoaded('stage', fn () => $this->resource->stage?->name),
            'pipeline' => $this->whenLoaded('pipeline', fn () => $this->resource->pipeline?->name),
            'expected_close_date' => $this->resource->expected_close_date?->toDateString(),
            'won_at' => $this->resource->won_at?->toIso8601String(),
            'closed_at' => $this->resource->closed_at?->toIso8601String(),
            'lost_reason' => $this->resource->lost_reason,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
