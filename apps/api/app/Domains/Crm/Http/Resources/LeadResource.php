<?php

namespace App\Domains\Crm\Http\Resources;

use App\Domains\Crm\Models\Lead;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @property Lead $resource
 */
class LeadResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'phone' => $this->resource->phone,
            'source' => $this->resource->source,
            'status' => $this->resource->status->value,
            'stage' => $this->whenLoaded('stage', fn () => $this->resource->stage?->name),
            'value_minor' => $this->resource->value_minor,
            'currency' => $this->resource->currency,
            'company_name' => $this->resource->company_name,
            'request_type' => $this->resource->request_type,
            'company_size' => $this->resource->company_size,
            'country' => $this->resource->country,
            'lead_score' => $this->resource->lead_score,
            'utm_source' => $this->resource->utm_source,
            'utm_medium' => $this->resource->utm_medium,
            'utm_campaign' => $this->resource->utm_campaign,
            'next_follow_up_at' => $this->resource->next_follow_up_at?->toIso8601String(),
            'last_contacted_at' => $this->resource->last_contacted_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
