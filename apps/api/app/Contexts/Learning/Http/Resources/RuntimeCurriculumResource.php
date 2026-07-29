<?php

namespace App\Contexts\Learning\Http\Resources;

use App\Contexts\Learning\Runtime\Data\RuntimeLessonData;
use App\Contexts\Learning\Runtime\Data\RuntimeSectionData;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * The runtime curriculum tree with per-lesson availability/lock/completion state. Public ids only.
 *
 * Expects: ['sections' => list<RuntimeSectionData>, 'enrollment' => Enrollment, 'course' => CourseRef].
 */
class RuntimeCurriculumResource extends BaseResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $enrollment = $this->resource['enrollment'];
        $course = $this->resource['course'];

        return [
            'course' => [
                'id' => $course->publicId,
                'title' => $course->title,
                'slug' => $course->slug,
            ],
            'enrollment' => [
                'id' => $enrollment->public_id,
                'status' => $enrollment->status->value,
                'progress_percentage' => $enrollment->progress_percentage,
            ],
            'sections' => array_map(
                fn (RuntimeSectionData $section): array => [
                    'id' => $section->publicId,
                    'title' => $section->title,
                    'lessons' => array_map($this->lesson(...), $section->lessons),
                ],
                $this->resource['sections'],
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function lesson(RuntimeLessonData $lesson): array
    {
        return [
            'id' => $lesson->publicId,
            'title' => $lesson->title,
            'type' => $lesson->type,
            'is_preview' => $lesson->isPreview,
            'has_media' => $lesson->hasMedia,
            'completed' => $lesson->completed,
            'locked' => $lesson->locked,
            'lock_reason' => $lesson->lockReason?->value,
            'prerequisites_met' => $lesson->prerequisitesMet,
            'released' => $lesson->released,
            'available_at' => $lesson->availableAt,
            'estimated_duration_seconds' => $lesson->estimatedDurationSeconds,
        ];
    }
}
