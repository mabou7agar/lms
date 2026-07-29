<?php

namespace App\Domains\Catalog\Services;

use App\Contexts\Learning\Enums\EnrollmentStatus;
use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Contracts\UserLookupPort;
use App\Platform\Shared\Assessment\Contracts\AssessmentStatsPort;
use App\Platform\Shared\Assessment\Data\AssessmentPassRate;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Curriculum\Data\CourseRef;
use App\Platform\Shared\Curriculum\Data\LessonRef;
use App\Platform\Shared\Curriculum\Data\SectionRef;
use App\Platform\Shared\Learning\Contracts\EnrollmentStatsPort;
use App\Platform\Shared\Learning\Data\EnrollmentStats;
use Illuminate\Support\Collection;

/**
 * Read-only analytics for the Instructor Portal. Concentrates the (baselined) cross-context
 * reads Catalog makes into Learning enrollments and the Shared curriculum port, so controllers
 * stay thin and the coupling surface is a single, auditable place.
 */
class InstructorAnalyticsService
{
    public function __construct(
        private readonly CurriculumReadPort $curriculum,
        private readonly UserLookupPort $users,
        private readonly AssessmentStatsPort $assessments,
        private readonly EnrollmentStatsPort $enrollments,
    ) {}

    /**
     * Per-course teaching stats.
     *
     * `assessment_pass_rate` is null when no attempt has been graded — that is "no data", not
     * zero, and the UI must render it as such rather than telling an instructor everyone failed.
     *
     * @return array{enrollments:int,completions:int,avg_progress:int,sections:int,lessons:int,assessment_pass_rate:int|null,graded_attempts:int}
     */
    public function courseStats(Course $course): array
    {
        $id = (int) $course->getKey();

        // M6: the enrollment aggregate now goes through EnrollmentStatsPort — the ONE canonical
        // calculator — instead of a fourth open-coded copy of the same count/sum/avg SQL. Same
        // grain (no window), so the figures are byte-identical to the previous inline query.
        $stats = $this->enrollments->statsPerCourse([$id])[$id];

        $tree = $this->curriculum->curriculumTree($id, false);

        // Quiz outcomes come through a port: Catalog may not read Assessment's tables, and
        // Assessment may not walk Authoring's curriculum. Each side answers only its own question.
        $passRate = $this->assessments->passRateForLessons($this->lessonIds($tree));

        return $this->buildCourseStats($stats, $tree, $passRate);
    }

    /**
     * H3: the same per-course stats for MANY courses without a query-per-course. Enrollment
     * aggregates and quiz outcomes are each computed in ONE batched round trip (statsPerCourse /
     * passRateForLessonGroups); the curriculum tree is read per course, exactly as the existing
     * paginated CoursePerformanceService does, because it is a structural read, not an aggregate.
     * Each returned payload is byte-identical to courseStats() for that course.
     *
     * @param  iterable<int, Course>  $courses
     * @return array<int, array{enrollments:int,completions:int,avg_progress:int,sections:int,lessons:int,assessment_pass_rate:int|null,graded_attempts:int}>
     */
    public function courseStatsForCourses(iterable $courses): array
    {
        /** @var Collection<int, Course> $courses */
        $courses = collect($courses);
        $ids = array_values($courses->map(static fn (Course $c): int => (int) $c->getKey())->all());

        if ($ids === []) {
            return [];
        }

        $statsByCourse = $this->enrollments->statsPerCourse($ids);

        /** @var array<int, array{course: ?CourseRef, sections: list<array{section: SectionRef, lessons: list<LessonRef>}>}> $trees */
        $trees = [];
        /** @var array<int, list<int>> $lessonGroups */
        $lessonGroups = [];

        foreach ($courses as $course) {
            $id = (int) $course->getKey();
            $trees[$id] = $this->curriculum->curriculumTree($id, false);
            $lessonGroups[$id] = $this->lessonIds($trees[$id]);
        }

        $passRates = $this->assessments->passRateForLessonGroups($lessonGroups);

        $result = [];
        foreach ($courses as $course) {
            $id = (int) $course->getKey();
            $result[$id] = $this->buildCourseStats($statsByCourse[$id], $trees[$id], $passRates[$id]);
        }

        return $result;
    }

    /**
     * @param  array{course: ?CourseRef, sections: list<array{section: SectionRef, lessons: list<LessonRef>}>}  $tree
     * @return array{enrollments:int,completions:int,avg_progress:int,sections:int,lessons:int,assessment_pass_rate:int|null,graded_attempts:int}
     */
    private function buildCourseStats(EnrollmentStats $stats, array $tree, AssessmentPassRate $passRate): array
    {
        return [
            'enrollments' => $stats->enrollments,
            'completions' => $stats->completions,
            'avg_progress' => $stats->averageProgress,
            'sections' => count($tree['sections']),
            'lessons' => array_sum(array_map(static fn (array $s): int => count($s['lessons']), $tree['sections'])),
            'assessment_pass_rate' => $passRate->passRate(),
            'graded_attempts' => $passRate->gradedAttempts,
        ];
    }

    /**
     * Flatten a curriculum tree to its internal lesson ids.
     *
     * @param  array{course: ?CourseRef, sections: list<array{section: SectionRef, lessons: list<LessonRef>}>}  $tree
     * @return list<int>
     */
    private function lessonIds(array $tree): array
    {
        $ids = [];

        foreach ($tree['sections'] as $section) {
            foreach ($section['lessons'] as $lesson) {
                $ids[] = $lesson->id;
            }
        }

        return $ids;
    }

    /**
     * Dashboard aggregate across all courses trained by the given user id.
     *
     * @return array{
     *   courses: array{total:int, draft:int, published:int, archived:int},
     *   students: int,
     *   completions: int,
     *   recent_enrollments: list<array{
     *     course:array{id:string,title:string}, student:array{id:?string,name:?string},
     *     status:string, progress_percentage:int, enrolled_at:?string
     *   }>
     * }
     */
    public function dashboard(int $userId): array
    {
        /** @var array<int, string> $courseTitles course_id => title */
        $courseTitles = Course::query()->forTrainer($userId)
            ->pluck('title', 'id')->all();
        $courseIds = array_keys($courseTitles);

        /** @var array<int, string> $publicIds course_id => public_id */
        $publicIds = Course::query()->forTrainer($userId)
            ->pluck('public_id', 'id')->all();

        $byStatus = Course::query()->forTrainer($userId)
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')->all();

        $students = $courseIds === [] ? 0 : (int) Enrollment::query()
            ->whereIn('course_id', $courseIds)
            ->distinct('user_id')
            ->count('user_id');

        $completions = $courseIds === [] ? 0 : (int) Enrollment::query()
            ->whereIn('course_id', $courseIds)
            ->where('status', EnrollmentStatus::Completed->value)
            ->count();

        $recent = $courseIds === [] ? collect() : Enrollment::query()
            ->whereIn('course_id', $courseIds)
            ->latest('enrolled_at')
            ->limit(10)
            ->get(['public_id', 'user_id', 'course_id', 'status', 'progress_percentage', 'enrolled_at']);

        $refs = $this->users->refsByIds($recent->pluck('user_id')->map(static fn ($v): int => (int) $v)->all());

        $recentRows = $recent->map(function (Enrollment $e) use ($courseTitles, $publicIds, $refs): array {
            $ref = $refs[(int) $e->user_id] ?? null;

            return [
                'course' => [
                    'id' => (string) ($publicIds[$e->course_id] ?? ''),
                    'title' => (string) ($courseTitles[$e->course_id] ?? ''),
                ],
                'student' => [
                    'id' => $ref?->publicId,
                    'name' => $ref?->name,
                ],
                'status' => $e->status->value,
                'progress_percentage' => (int) $e->progress_percentage,
                'enrolled_at' => $e->enrolled_at?->toIso8601String(),
            ];
        })->values()->all();

        return [
            'courses' => [
                'total' => count($courseIds),
                'draft' => (int) ($byStatus[CourseStatus::Draft->value] ?? 0),
                'published' => (int) ($byStatus[CourseStatus::Published->value] ?? 0),
                'archived' => (int) ($byStatus[CourseStatus::Archived->value] ?? 0),
            ],
            'students' => $students,
            'completions' => $completions,
            'recent_enrollments' => $recentRows,
        ];
    }
}
