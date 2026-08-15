<?php

namespace App\Platform\Blog\Http\Resources;

use App\Platform\Blog\Models\BlogPost;
use App\Platform\Shared\Media\Contracts\PublicAssetUrlResolver;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Compact blog-post representation for listings (the paginated index). `cover_image` is resolved to
 * a public URL (a MediaAsset reference becomes a stable public URL, a legacy value passes through,
 * missing/private => null). Locale-agnostic: emits both { en, ar } locales for title/excerpt so the
 * frontend picks the active locale via pickLocale.
 *
 * @property BlogPost $resource
 */
class BlogPostListResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $post = $this->resource;

        return [
            'id' => $post->public_id,
            'slug' => $post->slug,
            'title' => $post->title,
            'excerpt' => $post->excerpt,
            'cover_image' => app(PublicAssetUrlResolver::class)->resolve($post->cover_image),
            'category' => $post->category !== null ? [
                'slug' => $post->category->slug,
                'name' => $post->category->name,
            ] : null,
            // Boundary-safe author name resolved via UserLookupPort (stashed on the post by
            // BlogController); the Blog context never touches Identity's User model.
            'author' => $post->author_ref?->name,
            'is_featured' => $post->is_featured,
            'reading_minutes' => $post->reading_minutes,
            'published_at' => $post->published_at?->toIso8601String(),
        ];
    }
}
