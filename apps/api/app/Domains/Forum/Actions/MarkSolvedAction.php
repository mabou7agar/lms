<?php

declare(strict_types=1);

namespace App\Domains\Forum\Actions;

use App\Domains\Forum\Models\ForumPost;
use App\Domains\Forum\Models\ForumThread;
use Illuminate\Validation\ValidationException;

/**
 * Marks a thread solved by pinning an accepted answer post (or clears it when $post is null).
 * Instructor-only — the controller authorizes `moderate` via ForumThreadPolicy. The accepted post
 * must belong to the thread. `solved_post_id` is never mass-assignable.
 */
class MarkSolvedAction
{
    public function execute(ForumThread $thread, ?ForumPost $post): ForumThread
    {
        if ($post !== null && (int) $post->thread_id !== (int) $thread->id) {
            throw ValidationException::withMessages([
                'post_id' => 'The accepted answer does not belong to this thread.',
            ]);
        }

        $thread->forceFill(['solved_post_id' => $post?->id])->save();

        return $thread;
    }
}
