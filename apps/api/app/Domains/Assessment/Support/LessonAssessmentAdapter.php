<?php

namespace App\Domains\Assessment\Support;

use App\Domains\Assessment\Enums\AssessmentStatus;
use App\Domains\Assessment\Models\Assessment;
use App\Platform\Shared\Assessment\Contracts\LessonAssessmentPort;
use App\Platform\Shared\Assessment\Data\AssessmentRef;

/**
 * Assessment-side implementation of LessonAssessmentPort. The only place an Assessment Eloquent
 * model is converted into something another context may hold.
 */
class LessonAssessmentAdapter implements LessonAssessmentPort
{
    public function resolveAttachable(string $assessmentPublicId, int $courseId): ?AssessmentRef
    {
        $assessment = Assessment::query()
            ->withCount('questions')
            ->where('public_id', $assessmentPublicId)
            // Course scoping is enforced in the QUERY, not after the fact: an assessment belonging
            // to another course is indistinguishable from one that does not exist, so this cannot
            // be used to probe for assessments in courses the caller does not train.
            //
            // TENANT scoping rides on the SAME query: Assessment's CourseTenantScope (Option N) is a
            // global scope, so this lookup additionally requires the assessment's course to be visible
            // to the active tenant (global OR own-org). A cross-tenant attach — a global assessment
            // onto an org-private lesson, or an org-private assessment onto a global/other-org lesson —
            // therefore returns null and is rejected here, reusing the same-course guard below rather
            // than restating the tenant rule.
            ->where('course_id', $courseId)
            // Archived assessments stay readable for historical attempts but must not be attached
            // to anything new.
            ->whereIn('status', [AssessmentStatus::Draft->value, AssessmentStatus::Published->value])
            ->first();

        return $assessment === null ? null : $this->toRef($assessment);
    }

    public function describe(int $assessmentId): ?AssessmentRef
    {
        $assessment = Assessment::query()
            ->withCount('questions')
            ->find($assessmentId);

        return $assessment === null ? null : $this->toRef($assessment);
    }

    private function toRef(Assessment $assessment): AssessmentRef
    {
        return new AssessmentRef(
            id: (int) $assessment->id,
            publicId: (string) $assessment->public_id,
            title: (string) $assessment->title,
            status: $assessment->status->value,
            questionCount: (int) ($assessment->questions_count ?? 0),
            version: (int) $assessment->version,
        );
    }
}
