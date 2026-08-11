<?php

namespace App\Contexts\Learning\Analytics;

use App\Contexts\Learning\Enums\EnrollmentStatus;
use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Models\LearningSession;
use App\Contexts\Learning\Models\LessonVideoProgress;
use App\Platform\Shared\Assessment\Contracts\AssessmentResultPort;
use App\Platform\Shared\Certification\Contracts\CertificateStatusPort;
use App\Platform\Shared\Enterprise\Contracts\ManagerReportPort;
use App\Platform\Shared\Enterprise\Data\ManagerReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Learning's implementation of the enterprise MANAGER learning report (ManagerReportPort). The single
 * place the manager dashboard's learning metrics are aggregated, so the CRM enterprise portal reads
 * them without importing a Learning/Assessment/Certification model.
 *
 * TENANT ISOLATION is structural: the report never trusts the caller's user-id set on its own — it
 * always derives the concrete learner roster from `organization_members` constrained to ONE
 * organization id (and active membership), intersecting the caller-supplied department/team user set
 * only as a NARROWING filter. An org1 report therefore can never observe an org2 learner, and a
 * department scope can never widen past its members.
 *
 * QUERY DISCIPLINE: a fixed, small number of bounded aggregate queries (roster, one enrollment
 * aggregate, one watch-time SUM, one recent-session pluck, plus the two cross-context port calls) —
 * never a query per learner.
 */
class ManagerLearningReport implements ManagerReportPort
{
    public function __construct(
        private readonly CertificateStatusPort $certificates,
        private readonly AssessmentResultPort $assessments,
    ) {}

    public function report(
        int $organizationId,
        ?array $userIds = null,
        int $inactiveDays = 30,
        ?string $from = null,
        ?string $to = null,
        string $timezone = 'UTC',
        ?array $seatUsage = null,
    ): ManagerReport {
        // The authoritative learner roster: active members of THIS organization only, optionally
        // narrowed to a resolved department/team user set. This is the isolation seam.
        $roster = $this->rosterUserIds($organizationId, $userIds);

        if ($roster === []) {
            return $this->empty($organizationId, $seatUsage);
        }

        $agg = $this->enrollmentAggregate($roster, $from, $to, $timezone);

        $watchTotal = (int) LessonVideoProgress::query()
            ->whereIn('user_id', $roster)
            ->sum('watched_seconds');

        $learners = (int) $agg['learners'];

        $assessmentCounts = $this->assessments->outcomeCountsForUsers($roster);

        return new ManagerReport(
            organizationId: $organizationId,
            learners: $learners,
            enrollments: (int) $agg['enrollments'],
            started: (int) $agg['started'],
            completions: (int) $agg['completions'],
            avgProgress: round((float) $agg['avg_progress'], 2),
            watchTimeSeconds: $watchTotal,
            avgWatchTimeSecondsPerLearner: $learners > 0 ? (int) round($watchTotal / $learners) : 0,
            inactiveLearners: $this->inactiveCount($roster, $inactiveDays),
            assessmentsPassed: $assessmentCounts['passed'],
            assessmentsFailed: $assessmentCounts['failed'],
            certificatesIssued: $this->certificates->issuedCountForUsers($roster),
            seatsPurchased: $seatUsage['purchased'] ?? null,
            seatsUsed: $seatUsage['used'] ?? null,
            seatsAvailable: $seatUsage['available'] ?? null,
        );
    }

    /**
     * Distinct active-member user ids for the organization, narrowed to the resolved set when given.
     * Raw table read (not a CRM model) so Learning keeps its Shared-only dependency surface.
     *
     * @param  list<int>|null  $userIds
     * @return list<int>
     */
    private function rosterUserIds(int $organizationId, ?array $userIds): array
    {
        $query = DB::table('organization_members')
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->whereNotNull('user_id');

        if ($userIds !== null) {
            if ($userIds === []) {
                return [];
            }
            $query->whereIn('user_id', $userIds);
        }

        return $query->distinct()->pluck('user_id')
            ->map(static fn ($v): int => (int) $v)
            ->all();
    }

    /**
     * One aggregate over the roster's enrollments: total, started (progress>0), completions, average
     * progress, and distinct learners.
     *
     * @param  list<int>  $roster
     * @return array{enrollments: mixed, started: mixed, completions: mixed, avg_progress: mixed, learners: mixed}
     */
    private function enrollmentAggregate(array $roster, ?string $from, ?string $to, string $timezone): array
    {
        $query = Enrollment::query()->whereIn('user_id', $roster)->toBase();

        $this->applyWindow($query, $from, $to, $timezone);

        $row = $query
            ->selectRaw('count(*) as enrollments')
            ->selectRaw('coalesce(sum(case when progress_percentage > 0 then 1 else 0 end), 0) as started')
            ->selectRaw('coalesce(sum(case when status = ? then 1 else 0 end), 0) as completions', [EnrollmentStatus::Completed->value])
            ->selectRaw('coalesce(avg(progress_percentage), 0) as avg_progress')
            ->selectRaw('count(distinct user_id) as learners')
            ->first();

        $defaults = ['enrollments' => 0, 'started' => 0, 'completions' => 0, 'avg_progress' => 0, 'learners' => 0];

        return $row === null ? $defaults : array_merge($defaults, (array) $row);
    }

    /**
     * Roster learners with NO learning-session activity in the last $inactiveDays. One recent-session
     * read, differenced against the roster in memory.
     *
     * @param  list<int>  $roster
     */
    private function inactiveCount(array $roster, int $inactiveDays): int
    {
        $threshold = CarbonImmutable::now()->subDays(max(0, $inactiveDays));

        $active = LearningSession::query()
            ->whereIn('user_id', $roster)
            ->where('last_activity_at', '>=', $threshold)
            ->distinct()
            ->pluck('user_id')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        return count(array_diff($roster, $active));
    }

    /**
     * Half-open [startOfDay(from), startOfDay(to)+1day) window on enrolled_at — identical semantics to
     * OrganizationLearningReport / EnrollmentStatsAdapter (UTC default byte-for-byte unchanged, a valid
     * IANA zone shifts the boundary, an unknown zone falls through to UTC).
     */
    private function applyWindow(QueryBuilder $query, ?string $from, ?string $to, string $timezone): void
    {
        $zoned = $timezone !== 'UTC' && in_array($timezone, timezone_identifiers_list(), true);

        if ($from !== null) {
            $lower = $zoned
                ? CarbonImmutable::parse($from)->shiftTimezone($timezone)->startOfDay()->utc()
                : CarbonImmutable::parse($from)->startOfDay();
            $query->where('enrolled_at', '>=', $lower);
        }

        if ($to !== null) {
            $upper = $zoned
                ? CarbonImmutable::parse($to)->shiftTimezone($timezone)->startOfDay()->addDay()->utc()
                : CarbonImmutable::parse($to)->startOfDay()->addDay();
            $query->where('enrolled_at', '<', $upper);
        }
    }

    /**
     * @param  array{purchased: int, used: int, available: int}|null  $seatUsage
     */
    private function empty(int $organizationId, ?array $seatUsage): ManagerReport
    {
        return new ManagerReport(
            organizationId: $organizationId,
            learners: 0,
            enrollments: 0,
            started: 0,
            completions: 0,
            avgProgress: 0.0,
            watchTimeSeconds: 0,
            avgWatchTimeSecondsPerLearner: 0,
            inactiveLearners: 0,
            assessmentsPassed: 0,
            assessmentsFailed: 0,
            certificatesIssued: 0,
            seatsPurchased: $seatUsage['purchased'] ?? null,
            seatsUsed: $seatUsage['used'] ?? null,
            seatsAvailable: $seatUsage['available'] ?? null,
        );
    }
}
