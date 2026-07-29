<?php

namespace App\Domains\Assessment\Policies;

use App\Platform\Identity\Contracts\Actor;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Shared\Policies\BasePolicy;

/**
 * The gradebook and its CSV export are instructor-only, scoped to a single course. This is a
 * course-id check (not a model policy) because the gradebook is an aggregate over a course, not a
 * single Eloquent record — the controller resolves and passes the internal course id.
 */
class GradebookPolicy extends BasePolicy
{
    public function before(mixed $user, string $ability): ?bool
    {
        if ($user instanceof Actor && $user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    public function viewForCourse(Actor $user, int $courseId): bool
    {
        return app(CourseAccessPort::class)->canManageContent($user, $courseId);
    }
}
