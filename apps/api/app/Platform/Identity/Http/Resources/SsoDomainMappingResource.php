<?php

namespace App\Platform\Identity\Http\Resources;

use App\Platform\Identity\Models\SsoDomainMapping;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * @property SsoDomainMapping $resource
 */
class SsoDomainMappingResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'domain' => $this->resource->domain,
            'mode' => $this->resource->mode->value,
            'verified' => $this->resource->isVerified(),
            'verified_at' => $this->resource->verified_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
