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
use App\Platform\Shared\Analytics\AnalyticsEventName;
use App\Platform\Shared\Analytics\Contracts\AnalyticsEventRecorder;
use App\Platform\Shared\Analytics\Data\AnalyticsEventInput;
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
        private readonly AnalyticsEventRecorder $analytics,
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

        $this->recordAnalytics($result['enrollment'], $lessonId, (bool) $result['just_completed_lesson'], (bool) $result['just_completed_course']);

        return $result['progress'];
    }

    /**
     * Progress facts, recorded only on the transition. Re-opening a finished lesson is not a second
     * completion, and counting it as one would make "lessons completed" a measure of revisiting.
     *
     * Both are keyed per (enrollment, lesson) and per enrollment respectively, so the same
     * completion arriving twice — a retried request, a double-tap — records once.
     */
    private function recordAnalytics(Enrollment $enrollment, int $lessonId, bool $justCompletedLesson, bool $justCompletedCourse): void
    {
        $events = [];

        if ($justCompletedLesson) {
            $events[] = new AnalyticsEventInput(
                name: AnalyticsEventName::LessonCompleted->value,
                userId: (int) $enrollment->user_id,
                courseId: $enrollment->courseId(),
                metadata: ['lesson_id' => $lessonId],
                dedupKey: 'lesson_completed:'.$enrollment->id.':'.$lessonId,
            );
        }

        if ($justCompletedCourse) {
            $events[] = new AnalyticsEventInput(
                name: AnalyticsEventName::CourseCompleted->value,
                userId: (int) $enrollment->user_id,
                courseId: $enrollment->courseId(),
                dedupKey: 'course_completed:'.$enrollment->id,
            );
        }

        if ($events !== []) {
            $this->analytics->recordMany($events);
        }
    }
}
