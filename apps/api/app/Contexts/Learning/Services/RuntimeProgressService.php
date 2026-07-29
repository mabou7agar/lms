<?php

namespace App\Contexts\Learning\Services;

use App\Contexts\Learning\Enums\LessonProgressStatus;
use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Models\LessonProgress;
use App\Platform\Shared\Services\BaseService;

/**
 * Start / resume / viewed transitions for a lesson. Deliberately separate from ProgressService so
 * the "viewed" signal can NEVER regress a completed lesson (the legacy recordByLessonId path allows
 * setting any status; this one does not). Idempotent and safe under repeated or out-of-order events.
 */
class RuntimeProgressService extends BaseService
{
    /**
     * Move a lesson to in_progress the first time it is opened. A completed lesson is left completed
     * (no regression); an already in_progress lesson is a no-op.
     */
    public function markViewed(Enrollment $enrollment, int $lessonId): LessonProgress
    {
        $progress = LessonProgress::firstOrNew([
            'enrollment_id' => $enrollment->id,
            'lesson_id' => $lessonId,
        ]);

        if ($progress->exists && $progress->statusEnum() === LessonProgressStatus::Completed) {
            return $progress; // never regress a completed lesson
        }

        if (! $progress->exists || $progress->statusEnum() === LessonProgressStatus::NotStarted) {
            $progress->forceFill([
                'enrollment_id' => $enrollment->id,
                'lesson_id' => $lessonId,
                'status' => LessonProgressStatus::InProgress->value,
            ])->save();
        }

        return $progress;
    }
}
