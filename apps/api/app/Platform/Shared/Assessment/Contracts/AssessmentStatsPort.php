<?php

namespace App\Platform\Shared\Assessment\Contracts;

use App\Platform\Shared\Assessment\Data\AssessmentPassRate;

/**
 * Aggregate quiz outcomes, for surfaces that report on courses without belonging to Assessment.
 *
 * Separate from LessonAssessmentPort on purpose. That port answers authoring questions — "may I
 * attach this?", "what is attached?" — and an ArchitectureTest pins it to exactly those two
 * methods. Reporting is a different concern with a different consumer, and folding it in would
 * quietly turn a narrow authoring contract into a general-purpose repository.
 *
 * Keyed by LESSON id rather than assessment id because that is the question a course-level report
 * actually asks: attempts are taken from a lesson, and the caller already knows its curriculum.
 * It also means the caller never has to learn an assessment id it has no other use for.
 */
interface AssessmentStatsPort
{
    /**
     * Graded-attempt totals across the given lessons, optionally windowed.
     *
     * Only GRADED attempts count: an in-progress, abandoned or awaiting-review sitting has no
     * pass/fail outcome yet, and counting it either way would misreport. An empty lesson list
     * returns an empty result rather than querying.
     *
     * The window is applied to when the attempt was SUBMITTED, not started — an attempt belongs to
     * the period in which its outcome was produced. Bounds are inclusive.
     *
     * @param  list<int>  $lessonIds  internal lesson ids
     * @param  string|null  $from  ISO date, inclusive lower bound on submitted_at
     * @param  string|null  $to  ISO date, inclusive upper bound on submitted_at
     */
    public function passRateForLessons(array $lessonIds, ?string $from = null, ?string $to = null): AssessmentPassRate;

    /**
     * Pass rates for several independent lesson groups in ONE round trip.
     *
     * The caller supplies a map of its own key (typically a course id) to that group's lesson ids;
     * the result is keyed the same way. Assessment stays ignorant of what the keys mean — it never
     * learns about courses — while the caller avoids a query per row of a paginated table.
     *
     * Every supplied key appears in the result, with an empty result where a group has no graded
     * attempts, so the caller never has to handle a missing key.
     *
     * @param  array<int, list<int>>  $lessonGroups  caller key => lesson ids
     * @return array<int, AssessmentPassRate>
     */
    public function passRateForLessonGroups(array $lessonGroups, ?string $from = null, ?string $to = null): array;
}
