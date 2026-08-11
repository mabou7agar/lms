<?php

namespace App\Domains\Crm\Http\Resources;

use App\Domains\Crm\Models\SeatAssignment;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @property SeatAssignment $resource
 */
class SeatAssignmentResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'member_id' => (int) $this->resource->member_id,
            'assigned_at' => $this->resource->assigned_at?->toIso8601String(),
            'revoked_at' => $this->resource->revoked_at?->toIso8601String(),
            'active' => $this->resource->isActive(),
        ];
    }
}
