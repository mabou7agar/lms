<?php

declare(strict_types=1);

namespace App\Domains\Forum\Policies;

use App\Domains\Forum\Models\ForumPost;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Shared\Policies\BasePolicy;

/**
 * Authorization for forum posts.
 *
 *   - update / delete : the author, OR a course instructor, OR super_admin.
 *
 * Creating a post (replying) is authorized against the parent THREAD via ForumThreadPolicy, so it is
 * not restated here. The owning course is reached through the post's thread; instructor authority is
 * resolved via CourseAccessPort (no Course model import). before() bypasses super_admin for
 * Gate-routed checks; each method also short-circuits super_admin for direct invocation.
 */
class ForumPostPolicy extends BasePolicy
{
    public function __construct(private readonly CourseAccessPort $courses) {}

    public function before(mixed $user, string $ability): ?bool
    {
        if ($user instanceof Actor && $user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    public function update(Actor $user, ForumPost $post): bool
    {
        return $this->owns($user, $post) || $this->manages($user, $post);
    }

    public function delete(Actor $user, ForumPost $post): bool
    {
        return $this->update($user, $post);
    }

    private function manages(Actor $user, ForumPost $post): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        $courseId = (int) $post->thread->course_id;

        return $this->courses->canManageContent($user, $courseId);
    }

    private function owns(Actor $user, ForumPost $post): bool
    {
        return (int) $post->user_id === $user->actorId();
    }
}
