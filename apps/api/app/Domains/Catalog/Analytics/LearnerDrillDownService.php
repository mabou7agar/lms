<?php

namespace App\Domains\Catalog\Analytics;

use App\Domains\Catalog\Analytics\Data\LearnerProgressReport;
use App\Platform\Identity\Contracts\Data\UserRef;
use App\Platform\Shared\Assessment\Contracts\AssessmentResultPort;
use App\Platform\Shared\Certification\Contracts\CertificateStatusPort;
use App\Platform\Shared\Learning\Contracts\WatchTimePort;

/**
 * Composes one learner's course drill-down from the three Shared ports — Learning (progress /
 * watch-time), Assessment (required-assessment outcomes) and Certification (certificate status).
 * Catalog owns none of that data; it reads all of it across the boundary through ports and never
 * touches another context's model.
 *
 * The query budget is deliberately bounded and independent of curriculum size: the whole progress
 * detail is a single bounded query set inside {@see WatchTimePort::learnerProgressDetail()}, and the
 * assessment outcome is one required-id lookup plus a pass check per REQUIRED assessment — a figure
 * set by course configuration, not by the number of lessons or learners. Returns null when the
 * learner is not enrolled so the controller can answer 404 without leaking whether the learner exists.
 */
class LearnerDrillDownService
{
    public function __construct(
        private readonly WatchTimePort $watchTime,
        private readonly AssessmentResultPort $assessments,
        private readonly CertificateStatusPort $certificates,
    ) {}

    public function forLearner(int $courseId, UserRef $student): ?LearnerProgressReport
    {
        $detail = $this->watchTime->learnerProgressDetail($courseId, $student->id);

        // Not enrolled: the caller turns this null into a 404, indistinguishable from "no such
        // learner" so the endpoint is not an enrolment oracle.
        if ($detail === null) {
            return null;
        }

        $requiredIds = $this->assessments->requiredAssessmentIdsForCourse($courseId);

        $passed = 0;
        foreach ($requiredIds as $assessmentId) {
            if ($this->assessments->hasPassed($assessmentId, $student->id)) {
                $passed++;
            }
        }

        $total = count($requiredIds);

        return new LearnerProgressReport(
            student: $student,
            detail: $detail,
            requiredAssessments: $total,
            passedAssessments: $passed,
            // Vacuously true when the course requires no assessments — matching the completion rule.
            allRequiredAssessmentsPassed: $passed === $total,
            hasCertificate: $this->certificates->hasCertificate($courseId, $student->id),
        );
    }
}
