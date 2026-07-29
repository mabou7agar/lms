<?php

namespace App\Contexts\Learning\Support;

use App\Platform\Shared\Learning\Contracts\CourseNavigationPort;

/**
 * Default for {@see CourseNavigationPort}: free navigation off everywhere, so strict prerequisite
 * sequencing (the existing behavior) is preserved until a real settings provider is bound.
 */
final class NullCourseNavigationPort implements CourseNavigationPort
{
    public function allowsFreeNavigation(int $courseId): bool
    {
        return false;
    }
}
