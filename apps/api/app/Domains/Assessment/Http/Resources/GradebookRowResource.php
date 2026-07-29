<?php

namespace App\Domains\Assessment\Http\Resources;

use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * One gradebook row (a learner and their cells). The service already produced a plain array — this
 * resource is a thin pass-through so the row travels through the standard paginated envelope.
 *
 * @property array<string, mixed> $resource
 */
class GradebookRowResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $row */
        $row = $this->resource;

        return $row;
    }
}
