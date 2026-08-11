<?php

namespace App\Domains\Crm\Http\Resources;

use App\Domains\Crm\Models\Team;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @property Team $resource
 */
class TeamResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'name' => $this->resource->name,
            'department_id' => $this->resource->department_id === null ? null : (int) $this->resource->department_id,
            'manager_id' => $this->resource->managerId(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
