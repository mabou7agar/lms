<?php

declare(strict_types=1);

namespace App\Domains\Forum\Policies;

use App\Domains\Forum\Models\ForumThread;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Shared\Learning\Contracts\CourseEnrollmentPort;
use App\Platform\Shared\Policies\BasePolicy;

/**
 * Authorization for course discussion threads.
 *
 *   - view / create   : enrolled learner OR course instructor OR super_admin.
 *   - moderate         : course instructor OR super_admin (pin / lock / mark-solved).
 *   - update / delete  : the author, OR a course instructor, OR super_admin.
 *
 * before() grants super_admin a blanket bypass for Gate-routed checks; each ability method ALSO
 * short-circuits super_admin so it is safe when a method is invoked directly (createInCourse /
 * viewCourse take a scalar course id and are called outside the Gate). Instructor authority is
 * resolved through CourseAccessPort so this domain never imports the Course model; enrollment
 * through CourseEnrollmentPort so it never imports the Enrollment model.
 */
class ForumThreadPolicy extends BasePolicy
{
    public function __construct(
        private readonly CourseEnrollmentPort $enrollment,
        private readonly CourseAccessPort $courses,
    ) {}

    public function before(mixed $user, string $ability): ?bool
    {
        if ($user instanceof Actor && $user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    /** List / read threads of a course (scalar course id). */
    public function viewCourse(Actor $user, int $courseId): bool
    {
        return $this->participates($user, $courseId);
    }

    /** Start a thread in a course (scalar course id). */
    public function createInCourse(Actor $user, int $courseId): bool
    {
        return $this->participates($user, $courseId);
    }

    public function view(Actor $user, ForumThread $thread): bool
    {
        return $this->participates($user, $thread->courseId());
    }

    /** Pin / lock / mark-solved. */
    public function moderate(Actor $user, ForumThread $thread): bool
    {
        return $this->manages($user, $thread->courseId());
    }

    public function update(Actor $user, ForumThread $thread): bool
    {
        return $this->owns($user, $thread) || $this->manages($user, $thread->courseId());
    }

    public function delete(Actor $user, ForumThread $thread): bool
    {
        return $this->update($user, $thread);
    }

    private function participates(Actor $user, int $courseId): bool
    {
        if ($user->hasRole('super_admin')) {
            return true;
        }

        return $this->enrollment->isEnrolled($courseId, $user->actorId())
            || $this->courses->canManageContent($user, $courseId);
    }

    private function manages(Actor $user, int $courseId): bool
    {
        return $user->hasRole('super_admin') || $this->courses->canManageContent($user, $courseId);
    }

    private function owns(Actor $user, ForumThread $thread): bool
    {
        return (int) $thread->user_id === $user->actorId();
    }
}
