<?php

namespace App\Contexts\Learning\Http\Resources;

use App\Contexts\Learning\Runtime\Data\ProgressSummaryData;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Learner progress summary payload for one course.
 */
class ProgressSummaryResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ProgressSummaryData $data */
        $data = $this->resource;

        return [
            'course_id' => $data->coursePublicId,
            'status' => $data->enrollmentStatus,
            'progress_percentage' => $data->progressPercentage,
            'total_lessons' => $data->totalLessons,
            'completed_lessons' => $data->completedLessons,
            'course_completed' => $data->courseCompleted,
            'resume_lesson_id' => $data->resumeLessonPublicId,
        ];
    }
}
