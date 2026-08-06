<?php

namespace App\Domains\Catalog\Http\Resources;

use App\Domains\Catalog\Models\Category;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Category with optionally-loaded nested children.
 *
 * @property Category $resource
 */
class CategoryResource extends BaseResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->public_id,
            'name' => $this->resource->localized('name'),
            'slug' => $this->resource->slug,
            'description' => $this->resource->localized('description'),
            'position' => $this->resource->position,
            'children' => CategoryResource::collection($this->whenLoaded('children')),
        ];
    }
}
