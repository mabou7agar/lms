<?php

namespace App\Contexts\Learning\Actions\Progress;

use App\Contexts\Learning\Enums\LessonProgressStatus;
use App\Contexts\Learning\Events\CourseCompleted;
use App\Contexts\Learning\Events\LessonCompleted;
use App\Contexts\Learning\Events\LessonProgressRecorded;
use App\Contexts\Learning\Exceptions\LessonCompletionBlockedException;
use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Models\LessonProgress;
use App\Contexts\Learning\Services\LessonCompletionPolicy;
use App\Contexts\Learning\Services\ProgressService;
use App\Contexts\Learning\Services\RuntimeAccessService;
use App\Contexts\Learning\Services\VideoProgressService;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Media\Contracts\MediaAssetPort;

/**
 * Completes a lesson with SERVER-decided authority. Unlike the legacy progress endpoint (which
 * accepts a client-sent status and stays for backward compatibility), this action refuses to mark a
 * lesson complete until every requirement is met:
 *
 *   - runtime access (enrollment + prerequisites/drip) — reject inaccessible content;
 *   - required assignment satisfied and required blocks completed (LessonCompletionPolicy);
 *   - for a video lesson with media, the video watched to threshold (VideoProgressService).
 *
 * Completion writes go through the existing ProgressService (idempotent recompute) and dispatch the
 * existing CourseCompleted / LessonCompleted / LessonProgressRecorded events — certificate
 * eligibility and downstream listeners stay compatible.
 */
class CompleteLessonAction extends BaseAction
{
    public function __construct(
        private readonly RuntimeAccessService $access,
        private readonly LessonCompletionPolicy $policy,
        private readonly ProgressService $progress,
        private readonly VideoProgressService $video,
        private readonly CurriculumReadPort $curriculum,
        private readonly MediaAssetPort $mediaAssets,
    ) {}

    /**
     * Explicit completion request. Throws when a requirement is unmet.
     *
     * @throws LessonCompletionBlockedException
     */
    public function executeByUserId(int $userId, int $lessonId): LessonProgress
    {
        $enrollment = $this->access->assertLaunchable($userId, $lessonId);

        $reasons = $this->unmetRequirements($enrollment, $userId, $lessonId);
        if ($reasons !== []) {
            throw LessonCompletionBlockedException::withReasons($reasons);
        }

        return $this->complete($enrollment, $lessonId);
    }

    /**
     * Opportunistic completion after a block/video signal. No-op (returns null) when the lesson is
     * already complete or a requirement remains unmet; never throws.
     */
    public function autoCompleteIfEligible(Enrollment $enrollment, int $userId, int $lessonId): ?LessonProgress
    {
        $already = LessonProgress::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('lesson_id', $lessonId)
            ->where('status', LessonProgressStatus::Completed->value)
            ->exists();

        if ($already || $this->unmetRequirements($enrollment, $userId, $lessonId) !== []) {
            return null;
        }

        return $this->complete($enrollment, $lessonId);
    }

    /** @return list<string> */
    private function unmetRequirements(Enrollment $enrollment, int $userId, int $lessonId): array
    {
        $reasons = $this->policy->unmetContentRequirements($enrollment, $userId, $lessonId);

        $ref = $this->curriculum->lessonRefById($lessonId);
        $isVideoLesson = $ref !== null && $ref->type === 'video' && $this->mediaAssets->assetForLesson($lessonId) !== null;

        if ($isVideoLesson && ! $this->video->isCompletedFor($enrollment, $lessonId)) {
            $reasons[] = 'video_incomplete';
        }

        return $reasons;
    }

    private function complete(Enrollment $enrollment, int $lessonId): LessonProgress
    {
        $result = $this->transaction(
            fn () => $this->progress->recordByLessonId($enrollment, $lessonId, LessonProgressStatus::Completed)
        );

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
