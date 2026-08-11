<?php

namespace App\Domains\Crm\Services;

use App\Domains\Crm\Import\CsvSafety;
use App\Domains\Crm\Models\Organization;
use App\Platform\Shared\Analytics\Contracts\AnalyticsSummaryPort;
use App\Platform\Shared\Enterprise\Contracts\ManagerReportPort;
use App\Platform\Shared\Enterprise\Contracts\OrganizationSubscriptionPort;
use App\Platform\Shared\Services\BaseService;
use Illuminate\Support\Facades\DB;

/**
 * Builds the org BI/data-export BUNDLE: several flat, BI-connector-friendly CSV datasets plus a JSON
 * manifest describing them. Every dataset is confined to ONE organization and is data that org
 * legitimately owns — its own member roster, its own seat usage, and org-level LEARNING/ANALYTICS
 * AGGREGATES (never another org's rows, never platform-wide PII, never an individual learner outside
 * the org).
 *
 * Cross-context figures are read ONLY through Shared ports (OrganizationSubscriptionPort seat usage,
 * ManagerReportPort learning aggregates, AnalyticsSummaryPort KPI aggregates) — this service imports no
 * other context's models. Analytics money metrics are withheld (includeMoney: false): a data export is
 * not a revenue grant, so a currency figure never leaks into the bundle.
 *
 * QUERY DISCIPLINE: the member roster is a single joined query and every other dataset is a bounded
 * aggregate from a port — there is NO per-member query, so the bundle cost does not grow with headcount.
 */
class OrgExportService extends BaseService
{
    use CsvSafety;

    public function __construct(
        private readonly ManagerReportPort $reports,
        private readonly OrganizationSubscriptionPort $subscriptions,
        private readonly AnalyticsSummaryPort $analytics,
    ) {}

    /**
     * Build the in-memory bundle (manifest scalars + datasets). Writing to storage is the job's concern.
     *
     * @return array{
     *     manifest: array<string, mixed>,
     *     files: list<array{name: string, file: string, columns: list<string>, rows: list<list<int|string|float|null>>}>
     * }
     */
    public function build(Organization $organization): array
    {
        $organizationId = (int) $organization->id;

        $files = [
            $this->members($organizationId),
            $this->seatUsage($organizationId),
            $this->learningAggregates($organizationId),
            $this->analyticsAggregates($organizationId),
        ];

        $rowCount = array_sum(array_map(static fn (array $f): int => count($f['rows']), $files));

        $manifest = [
            'tenant' => (string) $organization->public_id,
            'organization_id' => $organizationId,
            'dataset' => 'bi_bundle',
            'generated_at' => now()->toIso8601String(),
            'row_count' => $rowCount,
            'files' => array_map(static fn (array $f): array => [
                'name' => $f['name'],
                'file' => $f['file'],
                'columns' => $f['columns'],
                'rows' => count($f['rows']),
            ], $files),
        ];

        return ['manifest' => $manifest, 'files' => $files];
    }

    /**
     * Serialize a dataset to a CSV string. Every cell is neutralized against formula injection so a
     * value an org stored is never weaponized when their BI tool opens the file.
     *
     * @param  list<string>  $columns
     * @param  list<list<int|string|float|null>>  $rows
     */
    public function toCsv(array $columns, array $rows): string
    {
        $handle = fopen('php://temp', 'r+b');
        fputcsv($handle, $columns, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(function ($cell): string {
                return $this->neutralize($cell === null ? '' : (string) $cell);
            }, $row), ',', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * The org's OWN member roster (roster data the org owns). One joined query — no per-member lookup.
     *
     * @return array{name: string, file: string, columns: list<string>, rows: list<list<int|string|float|null>>}
     */
    private function members(int $organizationId): array
    {
        $columns = ['member_id', 'email', 'role', 'status', 'department', 'joined_at'];

        $records = DB::table('organization_members as m')
            ->leftJoin('crm_departments as d', 'd.id', '=', 'm.department_id')
            ->where('m.organization_id', $organizationId)
            ->orderBy('m.id')
            ->get(['m.public_id', 'm.email', 'm.role', 'm.status', 'd.name as department_name', 'm.joined_at']);

        $rows = [];
        foreach ($records as $r) {
            $rows[] = [
                (string) $r->public_id,
                (string) $r->email,
                (string) $r->role,
                (string) $r->status,
                $r->department_name === null ? '' : (string) $r->department_name,
                $r->joined_at === null ? '' : (string) $r->joined_at,
            ];
        }

        return ['name' => 'members', 'file' => 'members.csv', 'columns' => $columns, 'rows' => $rows];
    }

    /**
     * Subscription seat usage via the Commerce->CRM Shared port. Zero rows when no active subscription.
     *
     * @return array{name: string, file: string, columns: list<string>, rows: list<list<int|string|float|null>>}
     */
    private function seatUsage(int $organizationId): array
    {
        $columns = ['subscription_id', 'status', 'seats_purchased', 'seats_used', 'seats_available'];

        $summary = $this->subscriptions->seatSummary($organizationId);

        $rows = $summary === null ? [] : [[
            $summary->subscriptionPublicId,
            $summary->status,
            $summary->purchased,
            $summary->used,
            $summary->available,
        ]];

        return ['name' => 'seat_usage', 'file' => 'seat_usage.csv', 'columns' => $columns, 'rows' => $rows];
    }

    /**
     * Org-level LEARNING aggregates via the Shared ManagerReportPort (bounded; no per-learner query).
     *
     * @return array{name: string, file: string, columns: list<string>, rows: list<list<int|string|float|null>>}
     */
    private function learningAggregates(int $organizationId): array
    {
        $report = $this->reports->report(organizationId: $organizationId)->toArray();

        // Flat metric/value shape — the friendliest form for a BI connector. Seats live in their own
        // dataset, so drop the nested seats key here.
        unset($report['seats'], $report['organization_id']);

        $rows = [];
        foreach ($report as $metric => $value) {
            $rows[] = [(string) $metric, is_scalar($value) ? $value : (string) json_encode($value)];
        }

        return ['name' => 'enrollments_completions', 'file' => 'enrollments_completions.csv', 'columns' => ['metric', 'value'], 'rows' => $rows];
    }

    /**
     * Org-scoped ANALYTICS KPI aggregates via the Shared AnalyticsSummaryPort. Money metrics are
     * withheld — a data export never carries a revenue figure.
     *
     * @return array{name: string, file: string, columns: list<string>, rows: list<list<int|string|float|null>>}
     */
    private function analyticsAggregates(int $organizationId): array
    {
        $summary = $this->analytics->summarize(includeMoney: false, organizationId: $organizationId)->toArray();

        $rows = [];
        foreach (($summary['metrics'] ?? []) as $key => $metric) {
            $value = is_array($metric) ? ($metric['value'] ?? null) : $metric;
            $rows[] = [(string) $key, $value === null ? '' : (is_scalar($value) ? $value : (string) json_encode($value))];
        }

        return ['name' => 'analytics_kpis', 'file' => 'analytics_kpis.csv', 'columns' => ['metric', 'value'], 'rows' => $rows];
    }
}
