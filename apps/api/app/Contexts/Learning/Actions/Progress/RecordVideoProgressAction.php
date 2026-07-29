<?php

namespace App\Contexts\Learning\Actions\Progress;

use App\Contexts\Learning\Models\LessonVideoProgress;
use App\Contexts\Learning\Services\RuntimeAccessService;
use App\Contexts\Learning\Services\VideoProgressService;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Records a video heartbeat. Authorizes the launch first (reject inaccessible/drip-locked content),
 * persists the throttled, server-authoritative progress, and — when the server decides the video
 * just crossed the completion threshold — opportunistically completes the lesson if its other
 * requirements are already met.
 */
class RecordVideoProgressAction extends BaseAction
{
    public function __construct(
        private readonly RuntimeAccessService $access,
        private readonly VideoProgressService $video,
        private readonly CompleteLessonAction $completeLesson,
    ) {}

    public function executeByUserId(int $userId, int $lessonId, int $positionSeconds, ?int $durationSeconds = null): LessonVideoProgress
    {
        $enrollment = $this->access->assertLaunchable($userId, $lessonId);

        $result = $this->transaction(
            fn () => $this->video->record($enrollment, $userId, $lessonId, $positionSeconds, $durationSeconds)
        );

        if ($result['just_completed_video']) {
            $this->completeLesson->autoCompleteIfEligible($enrollment, $userId, $lessonId);
        }

        return $result['progress'];
    }
}
