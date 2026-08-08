<?php

namespace App\Contexts\Learning\Analytics;

use App\Contexts\Learning\Enums\EnrollmentStatus;
use App\Contexts\Learning\Models\Enrollment;
use App\Platform\Shared\Tenancy\Contracts\CurrentTenantProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * ORGANIZATION-scoped learning reporting.
 *
 * Learner rows (enrollments, lesson_progress, certificates) are USER-OWNED / TENANT-CONSTRAINED —
 * they are NEVER strict-scoped, so a learner always reads their OWN records (a personal B2C learner
 * with no organization included). This service is the SEPARATE reporting path a manager uses to view
 * THEIR organization's learners, and it is the single enforcement seam that guarantees an org1
 * manager can never observe an org2 learner:
 *
 *   every learner is filtered through the organization_members membership join, constrained to ONE
 *   organization id, and that id is taken ONLY from the resolved tenant (CurrentTenantProvider) —
 *   never from client input.
 *
 * A learner who is not an active member of the organization simply does not appear in the report;
 * their own self-access is unaffected because this class is a reporting projection, not a scope on
 * the learner's models.
 *
 * The [from, to] day-boundary handling mirrors EnrollmentStatsAdapter exactly so Sprint 0.2 timezone
 * behaviour is preserved: UTC default is byte-for-byte identical to the pre-timezone path, a valid
 * IANA zone shifts the calendar-day boundary, and an unknown zone falls through to UTC.
 */
class OrganizationLearningReport
{
    public function __construct(private readonly CurrentTenantProvider $tenant) {}

    /**
     * Aggregate learning stats for the CURRENT tenant's organization only. Returns the empty envelope
     * when no organization tenant is resolved (a personal learner, an anonymous request, or a platform
     * admin with tenancy bypassed) — a manager report requires an org context.
     *
     * @return array{organization_id: int|null, learners: int, enrollments: int, completions: int}
     */
    public function forCurrentTenant(?string $from = null, ?string $to = null, string $timezone = 'UTC'): array
    {
        $organizationId = $this->tenant->currentTenant()?->value;

        if ($organizationId === null) {
            return $this->empty();
        }

        return $this->forOrganization((int) $organizationId, $from, $to, $timezone);
    }

    /**
     * Aggregate learning stats for a SPECIFIC organization. The organization id must originate from a
     * trusted (tenant-resolved) source — callers must never pass a client-supplied id.
     *
     * @return array{organization_id: int, learners: int, enrollments: int, completions: int}
     */
    public function forOrganization(int $organizationId, ?string $from = null, ?string $to = null, string $timezone = 'UTC'): array
    {
        $agg = $this->scoped($organizationId, $from, $to, $timezone)
            ->toBase()
            ->selectRaw('count(*) as enrollments')
            ->selectRaw('coalesce(sum(case when enrollments.status = ? then 1 else 0 end), 0) as completions', [EnrollmentStatus::Completed->value])
            ->first();

        $learners = (int) $this->scoped($organizationId, $from, $to, $timezone)
            ->distinct()
            ->count('enrollments.user_id');

        return [
            'organization_id' => $organizationId,
            'learners' => $learners,
            'enrollments' => (int) ($agg->enrollments ?? 0),
            'completions' => (int) ($agg->completions ?? 0),
        ];
    }

    /**
     * The DISTINCT learner user ids visible to a manager of the given organization — the concrete
     * roster that proves cross-org isolation (org1's roster never contains an org2-only learner).
     *
     * @return list<int>
     */
    public function learnerIdsForOrganization(int $organizationId, ?string $from = null, ?string $to = null, string $timezone = 'UTC'): array
    {
        return $this->scoped($organizationId, $from, $to, $timezone)
            ->toBase()
            ->distinct()
            ->pluck('enrollments.user_id')
            ->map(static fn ($v): int => (int) $v)
            ->all();
    }

    /** @return array{organization_id: null, learners: int, enrollments: int, completions: int} */
    private function empty(): array
    {
        return ['organization_id' => null, 'learners' => 0, 'enrollments' => 0, 'completions' => 0];
    }

    /**
     * Base query: enrollments joined to active organization membership for ONE organization. The
     * membership join is what confines the report to the org's own learners — there is no code path
     * here that can widen it to another organization.
     *
     * @return Builder<Enrollment>
     */
    private function scoped(int $organizationId, ?string $from, ?string $to, string $timezone = 'UTC'): Builder
    {
        // Raw table join (not an Eloquent Crm model) so this stays a DB-level read and Learning keeps
        // its Shared-only dependency surface. 'active' mirrors Crm's MemberStatus::Active — only active
        // members count toward the org's roster.
        $query = Enrollment::query()
            ->join('organization_members', 'organization_members.user_id', '=', 'enrollments.user_id')
            ->where('organization_members.organization_id', $organizationId)
            ->where('organization_members.status', 'active');

        // Half-open [startOfDay(from), startOfDay(to)+1day) window on enrolled_at — sargable and
        // identical in semantics to EnrollmentStatsAdapter (see its inline note). UTC default is
        // unchanged; a valid IANA zone shifts the day boundary; an unknown zone falls through to UTC.
        $zoned = $timezone !== 'UTC' && in_array($timezone, timezone_identifiers_list(), true);

        if ($from !== null) {
            $lower = $zoned
                ? CarbonImmutable::parse($from)->shiftTimezone($timezone)->startOfDay()->utc()
                : CarbonImmutable::parse($from)->startOfDay();

            $query->where('enrollments.enrolled_at', '>=', $lower);
        }

        if ($to !== null) {
            $upper = $zoned
                ? CarbonImmutable::parse($to)->shiftTimezone($timezone)->startOfDay()->addDay()->utc()
                : CarbonImmutable::parse($to)->startOfDay()->addDay();

            $query->where('enrollments.enrolled_at', '<', $upper);
        }

        return $query;
    }
}
