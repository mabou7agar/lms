<?php

namespace App\Platform\Identity\Http\Resources;

use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Read projection of a developer API key. Exposes ONLY safe metadata — name, granted scopes,
 * timestamps. It NEVER exposes the token hash (the `token` column) or any plaintext: the plaintext
 * is shown exactly once at creation time and is not recoverable thereafter.
 *
 * @property PersonalAccessToken $resource
 */
class ApiKeyResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var list<string> $scopes */
        $scopes = is_array($this->resource->abilities) ? array_values($this->resource->abilities) : [];

        return [
            'id' => $this->resource->getKey(),
            'name' => $this->resource->name,
            'scopes' => $scopes,
            'last_used_at' => $this->resource->last_used_at?->toIso8601String(),
            'expires_at' => $this->resource->expires_at?->toIso8601String(),
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
