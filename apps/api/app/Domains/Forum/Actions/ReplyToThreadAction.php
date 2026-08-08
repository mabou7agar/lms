<?php

declare(strict_types=1);

namespace App\Domains\Forum\Actions;

use App\Domains\Forum\Events\ForumPostCreated;
use App\Domains\Forum\Models\ForumPost;
use App\Domains\Forum\Models\ForumThread;
use App\Domains\Forum\Support\MentionParser;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Shared\Actions\BaseAction;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Posts a reply into a thread. The controller has already authorized participation (enrolled or
 * course instructor) via ForumThreadPolicy. This action owns the state invariants:
 *   - a LOCKED thread rejects a learner reply, but a course instructor / super_admin may still post
 *     (moderator follow-up);
 *   - nesting is capped at ONE level — a reply may target a top-level post only;
 *   - `is_instructor` is derived server-side (via CourseAccessPort) and stamped on the post;
 *   - `posts_count` is incremented atomically and `last_post_at` bumped, inside the transaction.
 */
class ReplyToThreadAction extends BaseAction
{
    public function __construct(private readonly CourseAccessPort $courses) {}

    public function execute(Actor $actor, ForumThread $thread, string $body, ?ForumPost $parent = null): ForumPost
    {
        $isInstructor = $actor->hasRole('super_admin')
            || $this->courses->canManageContent($actor, $thread->courseId());

        if ($thread->isLocked() && ! $isInstructor) {
            throw new AccessDeniedHttpException('This thread is locked.');
        }

        if ($parent !== null) {
            // The parent must belong to THIS thread, and it must itself be top-level (depth cap = 1).
            if ((int) $parent->thread_id !== (int) $thread->id) {
                throw ValidationException::withMessages([
                    'parent_post_id' => 'The parent post does not belong to this thread.',
                ]);
            }

            if (! $parent->isTopLevel()) {
                throw ValidationException::withMessages([
                    'parent_post_id' => 'Replies can only be one level deep.',
                ]);
            }
        }

        return $this->transaction(function () use ($actor, $thread, $body, $parent, $isInstructor): ForumPost {
            $post = new ForumPost;
            $post->fill([
                'thread_id' => $thread->id,
                'parent_post_id' => $parent?->id,
                'body' => $body, // sanitized by the model mutator
            ]);
            $post->forceFill([
                'user_id' => $actor->actorId(),
                'is_instructor' => $isInstructor,
            ]);
            $post->save();

            // Atomic counter bump + activity timestamp so concurrent replies cannot lose a count.
            $thread->increment('posts_count');
            $thread->forceFill(['last_post_at' => now()])->save();

            ForumPostCreated::dispatch(
                $post->id,
                $thread->id,
                $thread->courseId(),
                $actor->actorId(),
                MentionParser::handles($body),
            );

            return $post;
        });
    }
}
