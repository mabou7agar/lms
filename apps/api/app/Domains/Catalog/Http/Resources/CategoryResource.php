<?php

namespace App\Domains\Catalog\Http\Resources;

use App\Domains\Catalog\Models\Category;
use App\Platform\Shared\Media\Contracts\PublicAssetUrlResolver;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Category with optionally-loaded nested children.
 *
 * P1: `image` resolves the stored `image_path` (a MediaAsset public_id reference or a legacy URL) to
 * a public URL — PUBLIC asset -> stable URL, legacy -> passthrough, private/missing -> null.
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
            'image' => app(PublicAssetUrlResolver::class)->resolve($this->resource->image_path),
            'position' => $this->resource->position,
            'children' => CategoryResource::collection($this->whenLoaded('children')),
        ];
    }
}
