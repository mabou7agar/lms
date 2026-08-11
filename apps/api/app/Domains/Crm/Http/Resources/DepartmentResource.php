<?php

namespace App\Domains\Crm\Http\Resources;

use App\Domains\Crm\Models\Department;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @property Department $resource
 */
class DepartmentResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'name' => $this->resource->name,
            'manager_id' => $this->resource->managerId(),
            'members_count' => $this->whenCounted('members'),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
