<?php

namespace App\Contexts\Learning\Services;

use App\Contexts\Learning\Enums\LessonLockReason;
use App\Contexts\Learning\Enums\LessonProgressStatus;
use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Models\LessonProgress;
use App\Contexts\Learning\Runtime\Data\RuntimeLessonData;
use App\Contexts\Learning\Runtime\Data\RuntimeSectionData;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Curriculum\Data\LessonRef;
use App\Platform\Shared\Learning\Contracts\CourseNavigationPort;
use App\Platform\Shared\Learning\Contracts\LessonAvailabilityPort;
use App\Platform\Shared\Services\BaseService;
use Carbon\CarbonInterface;

/**
 * Builds the learner runtime curriculum read model: modules/lessons in curriculum order, each with
 * its computed availability state (completed / locked / lock reason / prerequisite state / drip
 * release). Published content only. Returns render-safe DTOs — no authoring-only fields.
 *
 * Query budget is CONSTANT in lesson count (it never re-reads per lesson): one completed-progress
 * query, one batched prerequisite query (via LessonAccessService::accessibleLessonIds), and one
 * batched drip query. This mirrors the bounded-query guarantee the existing player relies on.
 */
class CurriculumRuntimeService extends BaseService
{
    public function __construct(
        private readonly CurriculumReadPort $curriculum,
        private readonly LessonAccessService $access,
        private readonly LessonAvailabilityPort $availability,
        private readonly CourseNavigationPort $navigation,
    ) {}

    /** @return list<RuntimeSectionData> */
    public function build(Enrollment $enrollment): array
    {
        $courseId = $enrollment->courseId();
        $tree = $this->curriculum->curriculumTree($courseId, publishedOnly: true);

        /** @var list<LessonRef> $allRefs */
        $allRefs = [];
        foreach ($tree['sections'] as $node) {
            foreach ($node['lessons'] as $lessonRef) {
                $allRefs[] = $lessonRef;
            }
        }

        $completedIds = LessonProgress::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('status', LessonProgressStatus::Completed->value)
            ->pluck('lesson_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $accessibleIds = array_flip($this->access->accessibleLessonIds($allRefs, $completedIds));
        $completedFlip = array_flip($completedIds);

        $lessonIds = array_map(static fn (LessonRef $ref): int => $ref->id, $allRefs);
        $releaseMap = $allRefs === []
            ? []
            : $this->availability->releaseAtForLessons(
                $lessonIds,
                $enrollment->enrolled_at ?? $enrollment->created_at ?? now(),
            );

        $freeNavigation = $this->navigation->allowsFreeNavigation($courseId);

        $sections = [];
        foreach ($tree['sections'] as $node) {
            $lessons = [];
            foreach ($node['lessons'] as $ref) {
                $lessons[] = $this->lessonData($ref, $completedFlip, $accessibleIds, $releaseMap, $freeNavigation);
            }

            $sections[] = new RuntimeSectionData(
                publicId: $node['section']->publicId,
                title: $node['section']->title,
                lessons: $lessons,
            );
        }

        return $sections;
    }

    /**
     * @param  array<int, int>  $completedFlip
     * @param  array<int, int>  $accessibleIds
     * @param  array<int, CarbonInterface|null>  $releaseMap
     */
    private function lessonData(LessonRef $ref, array $completedFlip, array $accessibleIds, array $releaseMap, bool $freeNavigation): RuntimeLessonData
    {
        $completed = isset($completedFlip[$ref->id]);
        $prerequisitesMet = $ref->isPreview || isset($accessibleIds[$ref->id]);

        $releaseAt = $releaseMap[$ref->id] ?? null;
        $released = $ref->isPreview || $releaseAt === null || ! $releaseAt->isFuture();

        // Free navigation relaxes prerequisites but never drip. Preview lessons are always open.
        $sequenceLocked = ! $freeNavigation && ! $prerequisitesMet;
        $locked = ! $ref->isPreview && ($sequenceLocked || ! $released);

        $lockReason = null;
        if ($locked) {
            $lockReason = ! $released ? LessonLockReason::Drip : LessonLockReason::Prerequisite;
        }

        return new RuntimeLessonData(
            publicId: $ref->publicId,
            title: $ref->title,
            type: $ref->type,
            isPreview: $ref->isPreview,
            hasMedia: $ref->hasMedia,
            completed: $completed,
            locked: $locked,
            lockReason: $lockReason,
            prerequisitesMet: $prerequisitesMet,
            released: $released,
            availableAt: $releaseAt?->toIso8601String(),
            estimatedDurationSeconds: null, // best-effort; populated once a duration read model exists
        );
    }
}
