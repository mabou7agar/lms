<?php

namespace App\Contexts\Learning\Actions\Progress;

use App\Contexts\Learning\Enums\LessonProgressStatus;
use App\Contexts\Learning\Events\CourseCompleted;
use App\Contexts\Learning\Events\LessonCompleted;
use App\Contexts\Learning\Events\LessonProgressRecorded;
use App\Contexts\Learning\Exceptions\LessonCompletionBlockedException;
use App\Contexts\Learning\Models\LessonProgress;
use App\Contexts\Learning\Services\LessonAccessService;
use App\Contexts\Learning\Services\LessonCompletionPolicy;
use App\Contexts\Learning\Services\ProgressService;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Records progress for a lesson. Idempotent per lesson; recomputes completion and dispatches
 * events after commit. Requires access (enrollment + prerequisites) via LessonAccessService.
 */
class RecordLessonProgressAction extends BaseAction
{
    public function __construct(
        private readonly LessonAccessService $access,
        private readonly ProgressService $progress,
        private readonly LessonCompletionPolicy $completion,
    ) {}

    public function executeByUserId(int $userId, int $lessonId, LessonProgressStatus $status, ?int $positionSeconds = null): LessonProgress
    {
        $enrollment = $this->access->assertAccessByUserId($userId, $lessonId);

        // Security: this legacy endpoint accepts a client-sent status for backward compatibility, but
        // it must NOT let a learner self-report completion of a lesson whose content requirements
        // (required assignment / required blocks) are unmet — that bypass forged course completion
        // and minted a certificate. Gate completion on the same policy CompleteLessonAction enforces.
        if ($status === LessonProgressStatus::Completed) {
            $reasons = $this->completion->unmetContentRequirements($enrollment, $userId, $lessonId);
            if ($reasons !== []) {
                throw LessonCompletionBlockedException::withReasons($reasons);
            }
        }

        $result = $this->transaction(fn () => $this->progress->recordByLessonId($enrollment, $lessonId, $status, $positionSeconds));

        LessonProgressRecorded::dispatch($result['enrollment'], $lessonId);

        if ($result['just_completed_lesson']) {
            LessonCompleted::dispatch($result['enrollment'], $lessonId);
        }

        if ($result['just_completed_course']) {
            CourseCompleted::dispatch($result['enrollment']);
        }

        return $result['progress'];
    }
}
