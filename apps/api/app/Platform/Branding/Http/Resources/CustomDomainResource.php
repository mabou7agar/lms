<?php

namespace App\Platform\Branding\Http\Resources;

use App\Platform\Branding\Models\CustomDomain;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Public-safe view of a custom domain for the org-admin UI. Exposes the external public_id, the host,
 * primary/verification flags and timestamps — never the internal bigint id or another org's data.
 *
 * @property CustomDomain $resource
 */
class CustomDomainResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'host' => $this->resource->host,
            'is_primary' => $this->resource->is_primary,
            'verified' => $this->resource->isVerified(),
            'verified_at' => $this->resource->verified_at?->toIso8601String(),
            'verification_token' => $this->resource->verification_token,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
