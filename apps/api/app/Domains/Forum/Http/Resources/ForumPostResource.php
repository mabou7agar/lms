<?php

declare(strict_types=1);

namespace App\Domains\Forum\Http\Resources;

use App\Domains\Forum\Models\ForumPost;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Public shape of a forum post. Exposes public_id, sanitized body, the instructor badge, the author
 * as {name, public_id}, and any loaded one-level replies. Internal ids are never exposed.
 *
 * @property ForumPost $resource
 */
class ForumPostResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $post = $this->resource;

        return [
            'id' => $post->public_id,
            'body' => $post->body,
            'is_instructor' => (bool) $post->is_instructor,
            'parent_id' => $post->relationLoaded('parent') && $post->parent !== null
                ? $post->parent->public_id
                : null,
            'created_at' => $post->created_at?->toIso8601String(),
            'updated_at' => $post->updated_at?->toIso8601String(),
            'author' => ForumAuthor::for((int) $post->user_id),
            'replies' => $post->relationLoaded('replies')
                ? ForumPostResource::collection($post->replies)
                : null,
        ];
    }
}
