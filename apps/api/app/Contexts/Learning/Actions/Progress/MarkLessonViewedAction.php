<?php

namespace App\Contexts\Learning\Actions\Progress;

use App\Contexts\Learning\Events\LessonProgressRecorded;
use App\Contexts\Learning\Models\LessonProgress;
use App\Contexts\Learning\Services\RuntimeAccessService;
use App\Contexts\Learning\Services\RuntimeProgressService;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Records that a learner started/opened a lesson. Authorizes the launch (reject inaccessible
 * content), moves the lesson to in_progress without ever regressing a completed lesson, and emits
 * LessonProgressRecorded so the learning-session listener updates last-activity.
 */
class MarkLessonViewedAction extends BaseAction
{
    public function __construct(
        private readonly RuntimeAccessService $access,
        private readonly RuntimeProgressService $progress,
    ) {}

    public function executeByUserId(int $userId, int $lessonId): LessonProgress
    {
        $enrollment = $this->access->assertLaunchable($userId, $lessonId);

        $result = $this->transaction(fn () => $this->progress->markViewed($enrollment, $lessonId));

        LessonProgressRecorded::dispatch($enrollment, $lessonId);

        return $result;
    }
}
