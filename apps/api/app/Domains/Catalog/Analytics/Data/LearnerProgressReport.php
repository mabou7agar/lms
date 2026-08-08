<?php

namespace App\Domains\Catalog\Analytics\Data;

use App\Platform\Identity\Contracts\Data\UserRef;
use App\Platform\Shared\Learning\Data\LearnerProgressDetail;

/**
 * The instructor drill-down for one learner in one course: the learner's progress detail (from
 * Learning), their required-assessment outcome (from Assessment) and certificate status (from
 * Certification), composed in Catalog from the three Shared ports.
 *
 * Carries the boundary-safe {@see UserRef} — never an internal user id — so the resource can expose
 * the learner as name + public id and nothing else. Assessment outcomes are summarised as counts,
 * not per-assessment internal ids, keeping the payload free of ids a client has no use for.
 */
final readonly class LearnerProgressReport
{
    public function __construct(
        public UserRef $student,
        public LearnerProgressDetail $detail,
        public int $requiredAssessments,
        public int $passedAssessments,
        public bool $allRequiredAssessmentsPassed,
        public bool $hasCertificate,
    ) {}
}
