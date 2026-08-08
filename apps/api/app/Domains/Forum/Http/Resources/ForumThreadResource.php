<?php

declare(strict_types=1);

namespace App\Domains\Forum\Http\Resources;

use App\Domains\Forum\Models\ForumThread;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Public shape of a discussion thread. Exposes the external public_id (never the internal id), the
 * sanitized body, moderation flags as booleans, and the author as {name, public_id} only. Threads
 * are always drawn from tenant-scoped queries, so no cross-tenant row reaches this resource.
 *
 * @property ForumThread $resource
 */
class ForumThreadResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $thread = $this->resource;

        return [
            'id' => $thread->public_id,
            'title' => $thread->title,
            'body' => $thread->body,
            'pinned' => $thread->isPinned(),
            'locked' => $thread->isLocked(),
            'solved' => $thread->isSolved(),
            'solved_post' => $thread->relationLoaded('solvedPost') && $thread->solvedPost !== null
                ? $thread->solvedPost->public_id
                : null,
            'posts_count' => (int) $thread->posts_count,
            'last_post_at' => $thread->last_post_at?->toIso8601String(),
            'created_at' => $thread->created_at?->toIso8601String(),
            'updated_at' => $thread->updated_at?->toIso8601String(),
            'author' => ForumAuthor::for((int) $thread->user_id),
        ];
    }
}
