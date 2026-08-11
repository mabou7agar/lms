<?php

namespace App\Platform\Shared\Assessment\Contracts;

use App\Contexts\Learning\Support\NullAssessmentResultPort;

/**
 * Cross-context port for reading a LEARNER's assessment outcomes, DECLARED here in Shared and
 * IMPLEMENTED by the Assessment context. It answers only the questions the course-completion rule
 * asks — has this learner passed a given assessment, and has this learner passed every assessment a
 * course marks required — never anything about questions, versions, grading or attempts.
 *
 * Boundary-safe: scalar ids only, no Eloquent, no throwing. Until the Assessment context binds its
 * real adapter, {@see NullAssessmentResultPort} is the completion-safe default (nothing is required,
 * nothing is passed) so Learning's default policy never depends on Assessment being present.
 */
interface AssessmentResultPort
{
    /** True if the learner has at least one passing attempt at the given assessment. */
    public function hasPassed(int $assessmentId, int $userId): bool;

    /**
     * True if the learner has passed EVERY assessment the course flags required_for_completion.
     * Vacuously true when the course has no required assessments.
     */
    public function hasPassedAllRequiredForCourse(int $courseId, int $userId): bool;

    /**
     * The ids of the course's required-for-completion assessments (course-scoped + required +
     * attemptable), empty when none.
     *
     * @return list<int>
     */
    public function requiredAssessmentIdsForCourse(int $courseId): array;

    /**
     * Graded-attempt PASS/FAIL tallies across the given learner user ids — a bounded aggregate for the
     * enterprise manager report. Counts graded attempts only (passed = true / passed = false; not-yet-
     * graded null attempts are excluded from both). Empty ids => zeroes. The user ids MUST already be
     * authorization-scoped (an organization roster) by the caller.
     *
     * @param  list<int>  $userIds
     * @return array{passed: int, failed: int}
     */
    public function outcomeCountsForUsers(array $userIds): array;
}
