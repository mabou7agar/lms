<?php

namespace App\Platform\Blog\Http\Resources;

use App\Platform\Blog\Models\BlogCategory;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A blog category as seen by the public API. Locale-agnostic: emits both { en, ar } locales for
 * name/description so the frontend picks the active locale via pickLocale.
 *
 * @property BlogCategory $resource
 */
class BlogCategoryResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $category = $this->resource;

        return [
            'id' => $category->public_id,
            'slug' => $category->slug,
            'name' => $category->name,
            'description' => $category->description,
            'position' => $category->position,
        ];
    }
}
