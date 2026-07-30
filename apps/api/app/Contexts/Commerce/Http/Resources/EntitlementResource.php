<?php

namespace App\Contexts\Commerce\Http\Resources;

use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Read model for a single course entitlement of the current user. The resolver deals in internal
 * integer course ids only (resolving public ids would require reaching into Learning, which the
 * boundary forbids), so the entitlement is shaped as its course id. Read-only — no business logic.
 *
 * @property int $resource
 */
class EntitlementResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'course_id' => (int) $this->resource,
        ];
    }
}
