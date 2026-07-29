<?php

namespace App\Contexts\Learning\Analytics;

use App\Contexts\Learning\Enums\EnrollmentStatus;
use App\Contexts\Learning\Models\Enrollment;
use App\Platform\Shared\Learning\Contracts\EnrollmentStatsPort;
use App\Platform\Shared\Learning\Data\EnrollmentStats;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Learning's implementation of the enrollment reporting port. The only place outside this context's
 * own surfaces that enrollment aggregates are computed, so the coupling is one auditable file.
 *
 * ACTIVE LEARNER definition: an enrollment with status=active AND progress_percentage > 0, counted
 * as distinct users. This is a STATE, not a recency signal — "started and neither finished nor
 * cancelled", not "seen recently". A recency definition would need lesson_progress timestamps; that
 * is a defensible future refinement, but inventing one from enrollment rows alone would put a
 * number behind a word it does not mean.
 */
class EnrollmentStatsAdapter implements EnrollmentStatsPort
{
    public function statsForCourses(array $courseIds, ?string $from = null, ?string $to = null): EnrollmentStats
    {
        if ($courseIds === []) {
            return EnrollmentStats::empty();
        }

        // One grouped query for the row-level figures; the two DISTINCT counts cannot share it,
        // because counting distinct users and counting rows are different aggregates over the same
        // filtered set. Three queries total, each bounded by the same course-id set.
        $agg = $this->scoped($courseIds, $from, $to)
            ->toBase()
            ->selectRaw('count(*) as enrollments')
            ->selectRaw('coalesce(sum(case when status = ? then 1 else 0 end), 0) as completions', [EnrollmentStatus::Completed->value])
            ->selectRaw('coalesce(round(avg(progress_percentage)), 0) as avg_progress')
            ->first();

        // DISTINCT user_id: a learner enrolled in three of these courses is one learner.
        $uniqueLearners = (int) $this->scoped($courseIds, $from, $to)
            ->distinct('user_id')
            ->count('user_id');

        $activeLearners = (int) $this->scoped($courseIds, $from, $to)
            ->where('status', EnrollmentStatus::Active->value)
            ->where('progress_percentage', '>', 0)
            ->distinct('user_id')
            ->count('user_id');

        return new EnrollmentStats(
            enrollments: (int) ($agg->enrollments ?? 0),
            completions: (int) ($agg->completions ?? 0),
            uniqueLearners: $uniqueLearners,
            activeLearners: $activeLearners,
            averageProgress: (int) ($agg->avg_progress ?? 0),
        );
    }

    public function statsPerCourse(array $courseIds, ?string $from = null, ?string $to = null): array
    {
        // Seed every requested course with zeroes first: a course with no enrollments must appear
        // in the result, or the caller has to distinguish "absent" from "empty" at every use site.
        $result = array_fill_keys($courseIds, EnrollmentStats::empty());

        if ($courseIds === []) {
            return $result;
        }

        // ONE grouped query for the whole page rather than one per row. At this grain a learner
        // cannot enrol twice in the same course, so count(*) and distinct users coincide — which is
        // why this needs no second DISTINCT pass, unlike the cross-course aggregate above.
        $rows = $this->scoped($courseIds, $from, $to)
            ->toBase()
            ->selectRaw('course_id')
            ->selectRaw('count(*) as enrollments')
            ->selectRaw('coalesce(sum(case when status = ? then 1 else 0 end), 0) as completions', [EnrollmentStatus::Completed->value])
            ->selectRaw('coalesce(sum(case when status = ? and progress_percentage > 0 then 1 else 0 end), 0) as active', [EnrollmentStatus::Active->value])
            ->selectRaw('coalesce(round(avg(progress_percentage)), 0) as avg_progress')
            ->groupBy('course_id')
            ->get();

        foreach ($rows as $row) {
            $enrollments = (int) $row->enrollments;

            $result[(int) $row->course_id] = new EnrollmentStats(
                enrollments: $enrollments,
                completions: (int) $row->completions,
                uniqueLearners: $enrollments,
                activeLearners: (int) $row->active,
                averageProgress: (int) $row->avg_progress,
            );
        }

        return $result;
    }

    /**
     * @param  list<int>  $courseIds
     * @return Builder<Enrollment>
     */
    private function scoped(array $courseIds, ?string $from, ?string $to): Builder
    {
        $query = Enrollment::query()->whereIn('course_id', $courseIds);

        // Half-open range [startOfDay(from), startOfDay(to)+1day) instead of whereDate(). whereDate
        // wraps the column in DATE(enrolled_at), which is non-sargable — it cannot use an index on
        // enrolled_at and sequential-scans. Comparing the bare column against day boundaries keeps
        // the index usable and preserves the exact inclusive-day semantics: `DATE(col) >= from` is
        // `col >= from 00:00:00`, and `DATE(col) <= to` is `col < (to + 1 day) 00:00:00`. Columns are
        // UTC timestamps and the app timezone is UTC, so the boundaries are computed in UTC too —
        // identical to the prior behaviour. CarbonImmutable so the boundary instances are never
        // mutated in place.
        if ($from !== null) {
            $query->where('enrolled_at', '>=', CarbonImmutable::parse($from)->startOfDay());
        }

        if ($to !== null) {
            $query->where('enrolled_at', '<', CarbonImmutable::parse($to)->startOfDay()->addDay());
        }

        return $query;
    }
}
