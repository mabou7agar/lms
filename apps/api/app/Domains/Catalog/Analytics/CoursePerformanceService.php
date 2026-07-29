<?php

namespace App\Domains\Catalog\Analytics;

use App\Domains\Catalog\Contracts\CoursePublishGuard;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Models\CourseTrainer;
use App\Platform\Shared\Analytics\Data\MetricValue;
use App\Platform\Shared\Assessment\Contracts\AssessmentStatsPort;
use App\Platform\Shared\Curriculum\Contracts\CurriculumReadPort;
use App\Platform\Shared\Learning\Contracts\EnrollmentStatsPort;
use App\Platform\Shared\Publishing\Data\CourseReadinessInput;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Paginated per-course performance for an instructor.
 *
 * QUERY SHAPE — the reason this class exists rather than a loop over courseStats():
 *   1 query  page of courses (paginated, filtered, sorted at the database)
 *   1 query  enrollment aggregates for the whole page, grouped by course
 *   1 query  graded-attempt aggregates for the whole page, grouped by lesson
 *   N calls  curriculum tree per course on the page — needed for lesson ids AND readiness
 *
 * The first three are flat regardless of page size. The curriculum reads are per row and bounded by
 * per_page, which is capped at 50. Readiness is intentionally computed on the page only: it is a
 * multi-query evaluation and running it across an instructor's whole catalogue to render one table
 * would be far more expensive than the table is worth.
 *
 * Sorting is whitelisted and applied in SQL. Sorting in PHP after pagination would silently order
 * only the current page, which looks correct on page one and is wrong everywhere else.
 */
class CoursePerformanceService
{
    /** Sortable columns. Anything else is rejected rather than silently ignored. */
    public const SORTABLE = ['title', 'status', 'created_at', 'updated_at', 'published_at'];

    public const MAX_PER_PAGE = 50;

    /**
     * Readiness arrives through CoursePublishGuard — Catalog's OWN inbound contract — not through
     * the Authoring service that implements it. Catalog may not depend on Authoring, and reaching
     * for the concrete evaluator would also mean this table could disagree with the guard the
     * publish endpoint enforces.
     */
    public function __construct(
        private readonly CurriculumReadPort $curriculum,
        private readonly EnrollmentStatsPort $enrollments,
        private readonly AssessmentStatsPort $assessments,
        private readonly CoursePublishGuard $readiness,
    ) {}

    /**
     * @param  list<int>  $courseIds  already authorization-scoped
     * @param  array{search?:?string, status?:?string, sort?:?string, direction?:?string, per_page?:?int, date_from?:?string, date_to?:?string}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $courseIds, array $filters = []): LengthAwarePaginator
    {
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($filters['per_page'] ?? 15)));

        $query = Course::query()->whereIn('id', $courseIds);

        if (($search = $filters['search'] ?? null) !== null && $search !== '') {
            // The BACKSLASH IS ESCAPED FIRST, and that order is the whole point: escaping % and _
            // first would introduce backslashes that the backslash pass would then double, turning
            // an escaped wildcard back into a live one. PostgreSQL's LIKE uses backslash as the
            // default escape character, so `\%` matches a literal percent.
            //
            // Without this, a search for "%" is a wildcard matching every course — the query
            // silently returns the entire catalogue instead of the nothing it should.
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search);
            $query->where('title', 'like', '%'.$escaped.'%');
        }

        if (($status = $filters['status'] ?? null) !== null && in_array($status, CourseStatus::values(), true)) {
            $query->where('status', $status);
        }

        // The window filters when the course was last touched — the question a performance table
        // asks is "what have I worked on", not "what was created". Half-open range instead of
        // whereDate(): DATE(updated_at) is non-sargable, so it defeats any index and sequential-scans.
        // `col >= startOfDay(from)` and `col < startOfDay(to)+1day` preserve the inclusive-day
        // semantics exactly (columns and app timezone are both UTC). CarbonImmutable so the boundary
        // instances are never mutated in place.
        if (($from = $filters['date_from'] ?? null) !== null) {
            $query->where('updated_at', '>=', CarbonImmutable::parse($from)->startOfDay());
        }

        if (($to = $filters['date_to'] ?? null) !== null) {
            $query->where('updated_at', '<', CarbonImmutable::parse($to)->startOfDay()->addDay());
        }

        // Whitelist, not sanitisation: the value is compared against a fixed list of known column
        // names and replaced wholesale if it is not one of them, so nothing a caller supplies ever
        // reaches orderBy(). The form request rejects an unknown column with a 422 before it gets
        // here; this stays as the guarantee, because the request is one caller and not the contract.
        $requestedSort = $filters['sort'] ?? null;
        $sort = is_string($requestedSort) && in_array($requestedSort, self::SORTABLE, true)
            ? $requestedSort
            : 'updated_at';

        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $page = $query->orderBy($sort, $direction)->paginate($perPage);

        $rows = $this->decorate(
            $page->getCollection()->all(),
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
        );

        // A NEW paginator carrying the mapped rows, rather than setCollection() on the Eloquent
        // one. Two reasons. The counts, page number and links are copied across explicitly, so the
        // metadata is demonstrably preserved instead of relying on a mutator retaining it. And the
        // Eloquent paginator is generically typed to Course; swapping its collection for arrays is
        // a variance violation that PHPStan is right to reject — the honest expression of "a page
        // of rows" is a paginator of rows.
        return new LengthAwarePaginator(
            $rows,
            $page->total(),
            $page->perPage(),
            $page->currentPage(),
            [
                'path' => $page->path(),
                'pageName' => 'page',
                'query' => request()->query(),
            ],
        );
    }

    /**
     * Attach the aggregates to a page of courses using batched queries.
     *
     * @param  list<Course>  $courses
     * @return Collection<int, array<string, mixed>>
     */
    private function decorate(array $courses, ?string $from, ?string $to): Collection
    {
        $ids = array_map(static fn (Course $c): int => (int) $c->getKey(), $courses);

        $enrollmentStats = $this->enrollments->statsPerCourse($ids, $from, $to);

        // Curriculum once per course, reused for BOTH the lesson ids and the readiness evaluation
        // so the tree is not walked twice.
        $lessonGroups = [];
        $counts = [];

        foreach ($courses as $course) {
            $id = (int) $course->getKey();
            $tree = $this->curriculum->curriculumTree($id, false);
            $lessonIds = [];
            $lessonCount = 0;

            foreach ($tree['sections'] as $section) {
                foreach ($section['lessons'] as $lesson) {
                    $lessonIds[] = $lesson->id;
                    $lessonCount++;
                }
            }

            $lessonGroups[$id] = $lessonIds;
            $counts[$id] = ['sections' => count($tree['sections']), 'lessons' => $lessonCount];
        }

        $passRates = $this->assessments->passRateForLessonGroups($lessonGroups, $from, $to);

        // One query for trainer presence across the page instead of an exists() per row.
        $withTrainer = CourseTrainer::query()
            ->whereIn('course_id', $ids)
            ->distinct()
            ->pluck('course_id')
            ->map(static fn ($id): int => (int) $id)
            ->flip()
            ->all();

        // Collection's TValue is invariant, so a Collection of the precise row shape is NOT a
        // subtype of Collection<int, array<string, mixed>> even though every row satisfies it. The
        // annotation widens the value type deliberately; it is not silencing a real mismatch. The
        // declared shape stays as the contract because the row is consumed as JSON, and pinning the
        // full literal shape here would make every added metric a signature change.
        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = collect($courses)->map(function (Course $course) use ($enrollmentStats, $passRates, $counts, $withTrainer): array {
            $id = (int) $course->getKey();
            $stats = $enrollmentStats[$id];
            $rate = $passRates[$id]->passRate();
            $report = $this->readiness->report($this->readinessInput($course, isset($withTrainer[$id])));

            return [
                'id' => (string) $course->getAttribute('public_id'),
                'title' => (string) $course->getAttribute('title'),
                'slug' => (string) $course->getAttribute('slug'),
                'status' => $course->getAttribute('status')->value,
                'sections' => $counts[$id]['sections'],
                'lessons' => $counts[$id]['lessons'],

                'enrollment_count' => MetricValue::of($stats->enrollments)->toArray(),
                'unique_learners' => MetricValue::of($stats->uniqueLearners)->toArray(),
                'active_learners' => MetricValue::of($stats->activeLearners)->toArray(),
                'completion_rate' => ($cr = $stats->completionRate()) === null
                    ? MetricValue::noData('No enrollments yet.')->toArray()
                    : MetricValue::of($cr)->toArray(),
                'average_progress' => $stats->enrollments === 0
                    ? MetricValue::noData('No enrollments yet.')->toArray()
                    : MetricValue::of($stats->averageProgress)->toArray(),
                'assessment_pass_rate' => $rate === null
                    ? MetricValue::noData('No graded quiz attempts yet.')->toArray()
                    : MetricValue::of($rate)->toArray(),

                'publish_blocker_count' => count($report->blockers()),
                'warning_count' => count($report->warnings()),
                'readiness_score' => $report->score(),
                'is_publishable' => $report->isPublishable(),

                'last_updated_at' => $course->getAttribute('updated_at')?->toIso8601String(),
                'last_published_at' => $course->getAttribute('published_at')?->toIso8601String(),

                'revenue' => MetricValue::unavailable(
                    'Revenue analytics are not available for instructors yet.',
                )->toArray(),
            ];
        });

        return $rows;
    }

    private function readinessInput(Course $course, bool $hasInstructor): CourseReadinessInput
    {
        return new CourseReadinessInput(
            courseId: (int) $course->getKey(),
            coursePublicId: (string) $course->getAttribute('public_id'),
            description: $course->getAttribute('description'),
            thumbnailPath: $course->getAttribute('thumbnail_path'),
            hasInstructor: $hasInstructor,
            visibility: $course->getAttribute('visibility')?->value,
        );
    }
}
