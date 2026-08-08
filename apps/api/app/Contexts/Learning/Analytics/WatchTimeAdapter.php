<?php

namespace App\Contexts\Learning\Analytics;

use App\Contexts\Learning\Enums\LessonProgressStatus;
use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Models\LearningSession;
use App\Contexts\Learning\Models\LessonProgress;
use App\Contexts\Learning\Models\LessonVideoProgress;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Learning\Contracts\WatchTimePort;
use App\Platform\Shared\Learning\Data\CourseWatchTime;
use App\Platform\Shared\Learning\Data\InactiveLearners;
use App\Platform\Shared\Learning\Data\LearnerProgressDetail;
use App\Platform\Shared\Learning\Data\LessonDropOff;
use Carbon\CarbonImmutable;

/**
 * Learning's implementation of the instructor-facing watch-time / progress read model. The only
 * place outside this context's own surfaces that these tables are aggregated, so the cross-context
 * coupling stays one auditable file — mirroring EnrollmentStatsAdapter.
 *
 * Query discipline is the whole point of the design. Each method answers a whole-course question
 * with a fixed, small number of GROUPED or aggregate queries that do not grow with the number of
 * lessons or learners: the funnel is one grouped query folded against the curriculum order, the
 * distribution is one bucketed count, watch-time is one SUM, and the per-learner detail is a bounded
 * four-query set. Curriculum ORDER comes from CurriculumReadPort — Learning never reads Authoring
 * tables — and is bounded by the course, not the roster.
 */
class WatchTimeAdapter implements WatchTimePort
{
    public function __construct(private readonly CurriculumReadPort $curriculum) {}

    public function watchTimeForCourse(int $courseId): CourseWatchTime
    {
        // Denominator is the whole enrolled roster, not just learners who pressed play: averaging
        // only over players would overstate how much the cohort actually watches.
        $learnerCount = (int) Enrollment::query()->where('course_id', $courseId)->count();

        if ($learnerCount === 0) {
            return CourseWatchTime::empty();
        }

        $total = (int) LessonVideoProgress::query()
            ->whereIn('enrollment_id', Enrollment::query()->where('course_id', $courseId)->select('id'))
            ->sum('watched_seconds');

        return new CourseWatchTime(
            totalWatchedSeconds: $total,
            avgWatchedSecondsPerLearner: (int) round($total / $learnerCount),
            learnerCount: $learnerCount,
        );
    }

    public function lessonDropOff(int $courseId): array
    {
        $orderedRefs = $this->curriculum->orderedPublishedLessonRefs($courseId);
        $lessonIds = array_map(static fn ($ref): int => $ref->id, $orderedRefs);

        if ($lessonIds === []) {
            return [];
        }

        // ONE grouped query for every lesson's start/complete counts across the course's roster.
        // "Started" is any progress row not in the not-started state (in-progress OR completed);
        // "completed" is the completed subset. Scoped to the course via an enrollment-id subquery so
        // no other course's rows can leak in.
        $rows = LessonProgress::query()
            ->whereIn('lesson_id', $lessonIds)
            ->whereIn('enrollment_id', Enrollment::query()->where('course_id', $courseId)->select('id'))
            ->toBase()
            ->selectRaw('lesson_id')
            ->selectRaw('coalesce(sum(case when status <> ? then 1 else 0 end), 0) as started', [LessonProgressStatus::NotStarted->value])
            ->selectRaw('coalesce(sum(case when status = ? then 1 else 0 end), 0) as completed', [LessonProgressStatus::Completed->value])
            ->groupBy('lesson_id')
            ->get();

        /** @var array<int, array{started:int, completed:int}> $counts */
        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->lesson_id] = ['started' => (int) $row->started, 'completed' => (int) $row->completed];
        }

        // Rebuild in curriculum order, filling untouched lessons with zeroes so the whole funnel is
        // present without a per-lesson read at the call site.
        $result = [];
        foreach ($lessonIds as $lessonId) {
            $c = $counts[$lessonId] ?? ['started' => 0, 'completed' => 0];
            $result[$lessonId] = new LessonDropOff($lessonId, $c['started'], $c['completed']);
        }

        return $result;
    }

    public function inactiveLearners(int $courseId, int $sinceDays): InactiveLearners
    {
        /** @var list<int> $enrolled */
        $enrolled = Enrollment::query()
            ->where('course_id', $courseId)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($enrolled === []) {
            return InactiveLearners::empty();
        }

        $threshold = CarbonImmutable::now()->subDays(max(0, $sinceDays));

        /** @var list<int> $active */
        $active = LearningSession::query()
            ->where('course_id', $courseId)
            ->where('last_activity_at', '>=', $threshold)
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $activeLookup = array_flip($active);

        /** @var list<int> $inactive */
        $inactive = array_values(array_filter($enrolled, static fn (int $id): bool => ! isset($activeLookup[$id])));

        return new InactiveLearners(count($inactive), $inactive);
    }

    public function completionDistribution(int $courseId): array
    {
        // Fixed, ascending bucket set — every bucket present so the caller never reconciles a missing
        // key. Seeded to zero, then overwritten by the ONE bucketed count.
        $buckets = ['0' => 0, '1-25' => 0, '26-50' => 0, '51-75' => 0, '76-99' => 0, '100' => 0];

        $rows = Enrollment::query()
            ->where('course_id', $courseId)
            ->toBase()
            ->selectRaw(
                "case
                    when progress_percentage <= 0 then '0'
                    when progress_percentage between 1 and 25 then '1-25'
                    when progress_percentage between 26 and 50 then '26-50'
                    when progress_percentage between 51 and 75 then '51-75'
                    when progress_percentage between 76 and 99 then '76-99'
                    else '100'
                end as bucket"
            )
            ->selectRaw('count(*) as total')
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        foreach ($rows as $bucket => $total) {
            if (array_key_exists((string) $bucket, $buckets)) {
                $buckets[(string) $bucket] = (int) $total;
            }
        }

        return $buckets;
    }

    public function learnerProgressDetail(int $courseId, int $userId): ?LearnerProgressDetail
    {
        $enrollment = Enrollment::query()
            ->where('course_id', $courseId)
            ->where('user_id', $userId)
            ->first();

        // Not enrolled: a null return (not a zeroed detail) is the caller's 404 signal.
        if ($enrollment === null) {
            return null;
        }

        // Curriculum order, reused for BOTH the total lesson count AND the resume pointer so the tree
        // is walked once.
        $orderedRefs = $this->curriculum->orderedPublishedLessonRefs($courseId);
        $publishedIds = array_map(static fn ($ref): int => $ref->id, $orderedRefs);

        $completedIds = $publishedIds === []
            ? collect()
            : LessonProgress::query()
                ->where('enrollment_id', $enrollment->id)
                ->whereIn('lesson_id', $publishedIds)
                ->where('status', LessonProgressStatus::Completed->value)
                ->pluck('lesson_id')
                ->map(static fn ($id): int => (int) $id)
                ->flip();

        $watched = (int) LessonVideoProgress::query()
            ->where('enrollment_id', $enrollment->id)
            ->sum('watched_seconds');

        $session = LearningSession::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->first();

        // Resume pointer: first published lesson, in curriculum order, the learner has not completed.
        // Null once everything is done (or there are no lessons).
        $current = null;
        foreach ($orderedRefs as $ref) {
            if (! $completedIds->has($ref->id)) {
                $current = $ref;
                break;
            }
        }

        return new LearnerProgressDetail(
            currentLesson: $current,
            percentComplete: $enrollment->progressPercentage(),
            watchedSeconds: $watched,
            lessonsCompleted: $completedIds->count(),
            lessonsTotal: count($publishedIds),
            lastActivityAt: $session?->getAttribute('last_activity_at')?->toIso8601String(),
            startedAt: $enrollment->getAttribute('enrolled_at')?->toIso8601String(),
            completedAt: $enrollment->getAttribute('completed_at')?->toIso8601String(),
        );
    }
}
