<?php

namespace App\Domains\Assessment\Support;

use App\Domains\Assessment\Enums\AssessmentStatus;
use App\Domains\Assessment\Models\Assessment;
use App\Domains\Assessment\Models\AssessmentAttempt;
use App\Platform\Shared\Assessment\Contracts\AssessmentResultPort;

/**
 * Assessment-side implementation of AssessmentResultPort. The one place outside Assessment's own
 * surfaces that reads attempt PASS outcomes for a learner, so the coupling stays auditable in a
 * single file — mirroring how AssessmentStatsAdapter owns the reporting read.
 *
 * "Required for a course" means: an assessment whose authorization anchor is that course
 * (assessments.course_id), flagged required_for_completion, and Published (an unpublished or archived
 * assessment cannot be attempted, so gating on it would dead-end the learner). Tenant scoping rides
 * on Assessment's CourseTenantScope global scope automatically, so a required-assessment lookup can
 * never leak across orgs.
 */
class AssessmentResultAdapter implements AssessmentResultPort
{
    public function hasPassed(int $assessmentId, int $userId): bool
    {
        return AssessmentAttempt::query()
            ->where('assessment_id', $assessmentId)
            ->where('user_id', $userId)
            ->where('passed', true)
            ->exists();
    }

    public function hasPassedAllRequiredForCourse(int $courseId, int $userId): bool
    {
        foreach ($this->requiredAssessmentIdsForCourse($courseId) as $assessmentId) {
            if (! $this->hasPassed($assessmentId, $userId)) {
                return false;
            }
        }

        // Vacuously true when there are no required assessments.
        return true;
    }

    /** @return list<int> */
    public function requiredAssessmentIdsForCourse(int $courseId): array
    {
        return Assessment::query()
            ->where('course_id', $courseId)
            ->where('required_for_completion', true)
            ->where('status', AssessmentStatus::Published->value)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @return array{passed: int, failed: int}
     */
    public function outcomeCountsForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return ['passed' => 0, 'failed' => 0];
        }

        // ONE grouped aggregate over graded attempts (passed is non-null) — no per-learner query.
        $row = AssessmentAttempt::query()
            ->whereIn('user_id', $userIds)
            ->whereNotNull('passed')
            ->toBase()
            ->selectRaw('coalesce(sum(case when passed = ? then 1 else 0 end), 0) as passed', [true])
            ->selectRaw('coalesce(sum(case when passed = ? then 1 else 0 end), 0) as failed', [false])
            ->first();

        $data = (array) ($row ?? []);

        return [
            'passed' => (int) ($data['passed'] ?? 0),
            'failed' => (int) ($data['failed'] ?? 0),
        ];
    }
}
