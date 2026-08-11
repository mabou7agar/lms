<?php

namespace App\Platform\Shared\Enterprise\Contracts;

use App\Platform\Shared\Enterprise\Data\ManagerReport;

/**
 * Cross-context read model for an enterprise MANAGER's learning report. DECLARED here in Shared,
 * IMPLEMENTED by the Learning context (which owns enrollments / lesson-progress / video-progress /
 * learning-sessions and reads certificates + assessment outcomes through their own Shared ports),
 * CONSUMED by the CRM enterprise portal — so CRM never imports a Learning/Assessment/Certification
 * model.
 *
 * TENANCY: the organization id MUST originate from a trusted (tenant-resolved) source, and the
 * resolved member/user set from CRM's ManagerScope. The implementation additionally re-confines every
 * learner through the organization-membership join, so a report can never observe a learner outside
 * the organization even if the caller passes a wrong id set.
 *
 * QUERY DISCIPLINE: every metric is a bounded aggregate — no per-learner query.
 */
interface ManagerReportPort
{
    /**
     * Build the report for one organization, optionally narrowed to a resolved set of learner user
     * ids (a department/team scope). A null $userIds means "the whole organization roster".
     *
     * $seatUsage, when provided by the CRM caller, is folded into the returned snapshot as
     * [purchased, used, available]; null leaves the seat fields null.
     *
     * @param  list<int>|null  $userIds
     * @param  array{purchased: int, used: int, available: int}|null  $seatUsage
     */
    public function report(
        int $organizationId,
        ?array $userIds = null,
        int $inactiveDays = 30,
        ?string $from = null,
        ?string $to = null,
        string $timezone = 'UTC',
        ?array $seatUsage = null,
    ): ManagerReport;
}
