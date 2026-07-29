<?php

namespace App\Platform\Shared\Learning\Contracts;

use App\Platform\Shared\Learning\Data\EnrollmentStats;

/**
 * Enrollment aggregates for reporting surfaces that live outside Learning.
 *
 * Exists because a bounded context may not read another's tables. Catalog owns the instructor
 * dashboard but not enrollments; rather than reaching into `enrollments` directly — which is what
 * the older InstructorAnalyticsService does, grandfathered in the Deptrac baseline — it asks
 * Learning the aggregate question and receives scalars.
 *
 * Deliberately narrow: this answers "how are these courses doing" and nothing else. There is no
 * list(), no find(), no per-learner detail. Anything needing an individual learner's record is a
 * Learning concern and belongs behind its own surface.
 */
interface EnrollmentStatsPort
{
    /**
     * Aggregate enrollment figures across the given courses.
     *
     * The window applies to when the enrollment was CREATED (`enrolled_at`), so a date range means
     * "learners who joined in this period", not "learners active in this period". An empty course
     * list returns zeroes rather than querying — and callers must pass a scoped, non-empty set,
     * because an empty `whereIn` would otherwise silently widen to every course.
     *
     * @param  list<int>  $courseIds  internal course ids, already authorization-scoped
     * @param  string|null  $from  ISO date, inclusive
     * @param  string|null  $to  ISO date, inclusive
     */
    public function statsForCourses(array $courseIds, ?string $from = null, ?string $to = null): EnrollmentStats;

    /**
     * The same figures, but per course, in ONE round trip.
     *
     * Exists so a paginated course table does not issue a query per row. Callers get a map keyed by
     * course id; a course with no enrollments is present with zeroes rather than absent, so the
     * caller never has to distinguish "no data" from "not returned".
     *
     * uniqueLearners equals enrollments at this grain — a learner cannot enrol twice in one course
     * — but both are populated so the DTO means the same thing at either grain.
     *
     * @param  list<int>  $courseIds
     * @return array<int, EnrollmentStats>
     */
    public function statsPerCourse(array $courseIds, ?string $from = null, ?string $to = null): array;
}
