<?php

namespace App\Contexts\Learning\Actions\Progress;

use App\Contexts\Learning\Models\LearnerBlockProgress;
use App\Contexts\Learning\Services\BlockProgressService;
use App\Contexts\Learning\Services\RuntimeAccessService;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Marks a content block complete. Authorizes the launch first (reject inaccessible content), records
 * the block idempotently, then opportunistically completes the lesson if this block was the last
 * outstanding requirement.
 */
class CompleteBlockAction extends BaseAction
{
    public function __construct(
        private readonly RuntimeAccessService $access,
        private readonly BlockProgressService $blocks,
        private readonly CompleteLessonAction $completeLesson,
    ) {}

    public function executeByUserId(int $userId, int $lessonId, string $blockRef): LearnerBlockProgress
    {
        $enrollment = $this->access->assertLaunchable($userId, $lessonId);

        $progress = $this->transaction(
            fn () => $this->blocks->completeBlock($enrollment, $userId, $lessonId, $blockRef)
        );

        $this->completeLesson->autoCompleteIfEligible($enrollment, $userId, $lessonId);

        return $progress;
    }
}
