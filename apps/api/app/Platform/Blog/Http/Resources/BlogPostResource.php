<?php

namespace App\Platform\Blog\Http\Resources;

use App\Platform\Blog\Models\BlogPost;
use App\Platform\Shared\Media\Contracts\PublicAssetUrlResolver;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * A full blog post as seen by the public/preview API. Locale-agnostic: emits both { en, ar }
 * locales for title/excerpt/body and a fully-resolved SEO bag (stored overrides merged over
 * sensible fallbacks derived from the post). `cover_image` is resolved to a public URL via the
 * PublicAssetUrlResolver seam (a MediaAsset reference => stable public URL, legacy => pass-through,
 * missing/private => null).
 *
 * @property BlogPost $resource
 */
class BlogPostResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $post = $this->resource;
        $cover = app(PublicAssetUrlResolver::class)->resolve($post->cover_image);

        return [
            'id' => $post->public_id,
            'slug' => $post->slug,
            'title' => $post->title,
            'excerpt' => $post->excerpt,
            'body' => $post->body,
            'cover_image' => $cover,
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
            'updated_at' => $post->updated_at?->toIso8601String(),
            'seo' => $this->resolvedSeo($cover),
        ];
    }

    /**
     * The stored SEO overrides merged over fallbacks derived from the post content, so the
     * frontend always receives a complete, ready-to-use SEO block.
     *
     * @return array<string, mixed>
     */
    private function resolvedSeo(?string $cover): array
    {
        $post = $this->resource;
        /** @var array<string, mixed> $seo */
        $seo = $post->seo ?? [];

        return [
            'meta_title' => $seo['meta_title'] ?? $post->title,
            'meta_description' => $seo['meta_description'] ?? $post->excerpt,
            'keywords' => $seo['keywords'] ?? null,
            'canonical' => $seo['canonical'] ?? '/blog/'.ltrim($post->slug, '/'),
            'robots_index' => $seo['robots_index'] ?? true,
            'robots_follow' => $seo['robots_follow'] ?? true,
            'og_title' => $seo['og_title'] ?? ($seo['meta_title'] ?? $post->title),
            'og_description' => $seo['og_description'] ?? ($seo['meta_description'] ?? $post->excerpt),
            'og_image' => $seo['og_image'] ?? $cover,
            'twitter_card' => $seo['twitter_card'] ?? 'summary_large_image',
            'json_ld' => $seo['json_ld'] ?? null,
        ];
    }
}
