<?php

namespace App\Domains\Catalog\Actions\Course;

use App\Domains\Catalog\Events\CourseFeaturedToggled;
use App\Domains\Catalog\Models\Course;
use App\Platform\Shared\Actions\BaseAction;

class ToggleFeaturedAction extends BaseAction
{
    public function execute(Course $course): Course
    {
        $course = $this->transaction(function () use ($course): Course {
            $featured = ! $course->is_featured;

            $course->forceFill([
                'is_featured' => $featured,
                'featured_at' => $featured ? now() : null,
            ])->save();

            return $course;
        });

        CourseFeaturedToggled::dispatch($course);

        return $course;
    }
}
