<?php

declare(strict_types=1);

namespace App\Domains\Forum\Actions;

use App\Domains\Forum\Models\ForumPost;
use App\Domains\Forum\Models\ForumThread;
use Illuminate\Support\Facades\DB;

/**
 * Soft-deletes a post and keeps the parent thread's `posts_count` consistent (decremented, floored
 * at zero). If the deleted post was the accepted answer, the thread is un-solved. IDOR is guarded
 * upstream by ForumPostPolicy::delete (owner OR course instructor OR super_admin).
 */
class DeletePostAction
{
    public function execute(ForumPost $post): void
    {
        DB::transaction(function () use ($post): void {
            $threadId = (int) $post->thread_id;
            $postId = (int) $post->id;

            $post->delete();

            /** @var ForumThread|null $thread */
            $thread = ForumThread::query()->whereKey($threadId)->first();

            if ($thread === null) {
                return;
            }

            if ((int) $thread->posts_count > 0) {
                $thread->decrement('posts_count');
            }

            if ((int) $thread->solved_post_id === $postId) {
                $thread->forceFill(['solved_post_id' => null])->save();
            }
        });
    }
}
