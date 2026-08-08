<?php

namespace App\Domains\Catalog\Actions\Course;

use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Events\CourseUnpublished;
use App\Domains\Catalog\Models\Course;
use App\Platform\Shared\Actions\BaseAction;

class UnpublishCourseAction extends BaseAction
{
    public function execute(Course $course): Course
    {
        $course = $this->transaction(function () use ($course): Course {
            // Unpublish targets the distinct Unpublished state (not Draft): a course pulled from the
            // catalog is meaningfully different from one that was never published. Both are equally
            // non-public — scopePublished/scopeVisible exclude Unpublished exactly as they do Draft.
            $course->forceFill(['status' => CourseStatus::Unpublished->value])->save();

            return $course;
        });

        CourseUnpublished::dispatch($course);

        return $course;
    }
}
