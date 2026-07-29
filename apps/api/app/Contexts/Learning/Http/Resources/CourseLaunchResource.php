<?php

namespace App\Contexts\Learning\Http\Resources;

use App\Contexts\Learning\Runtime\Data\CourseLaunchData;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Learner course-shell payload for a launch. Public ids only; no media identifiers, no
 * authoring-only fields.
 */
class CourseLaunchResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CourseLaunchData $data */
        $data = $this->resource;

        return [
            'course' => [
                'id' => $data->coursePublicId,
                'title' => $data->title,
                'slug' => $data->slug,
            ],
            'enrollment' => [
                'id' => $data->enrollmentPublicId,
                'status' => $data->enrollmentStatus,
                'progress_percentage' => $data->progressPercentage,
            ],
            'progress' => [
                'total_lessons' => $data->totalLessons,
                'completed_lessons' => $data->completedLessons,
            ],
            'resume' => $data->resumeLessonPublicId === null ? null : [
                'lesson_id' => $data->resumeLessonPublicId,
                'title' => $data->resumeLessonTitle,
            ],
        ];
    }
}
