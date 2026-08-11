<?php

namespace App\Domains\Crm\Http\Resources;

use App\Domains\Crm\Models\CrmTask;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @property CrmTask $resource
 */
class CrmTaskResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'title' => $this->resource->title,
            'type' => $this->resource->type->value,
            'status' => $this->resource->status->value,
            'priority' => $this->resource->priority,
            'due_at' => $this->resource->due_at?->toIso8601String(),
            'completed_at' => $this->resource->completed_at?->toIso8601String(),
            'assigned_to' => $this->resource->assigned_to,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
