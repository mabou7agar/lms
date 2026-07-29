<?php

namespace App\Platform\Shared\Learning\Contracts;

use App\Contexts\Learning\Support\NullCourseNavigationPort;

/**
 * Cross-context port DECLARED by Learning to read a course's sequencing setting. Implemented by the
 * context that owns course settings (Authoring/Catalog); Learning only consumes it.
 *
 * When a course allows free navigation, prerequisite locks are advisory: a learner may launch any
 * published lesson regardless of prerequisite completion (drip release is still enforced — free
 * navigation relaxes SEQUENCING, not scheduling). The default binding
 * {@see NullCourseNavigationPort} returns false, preserving the
 * existing strict-prerequisite behavior everywhere until a real provider is bound.
 */
interface CourseNavigationPort
{
    public function allowsFreeNavigation(int $courseId): bool;
}
