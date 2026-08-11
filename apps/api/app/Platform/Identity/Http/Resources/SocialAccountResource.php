<?php

namespace App\Platform\Identity\Http\Resources;

use App\Platform\Identity\Models\SocialAccount;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A user's linked social/SSO account, boundary-safe.
 *
 * SECURITY: exposes ONLY the provider key, the display email captured at link time, and the link
 * timestamp. No provider subject id, no access/refresh tokens, no secrets — none of which are even
 * stored, but this resource is the enforced allow-list regardless.
 *
 * @property SocialAccount $resource
 */
class SocialAccountResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'provider' => $this->resource->provider,
            'email' => $this->resource->email,
            'linked_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
