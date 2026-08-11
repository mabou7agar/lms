<?php

namespace App\Domains\Crm\Http\Controllers\Api\V1\Enterprise;

use App\Domains\Crm\Models\Department;
use App\Domains\Crm\Models\OrganizationMember;
use App\Domains\Crm\Models\Team;
use App\Domains\Crm\Services\ManagerScope;
use App\Domains\Crm\Services\ManagerScopeResult;
use App\Platform\Shared\Enterprise\Contracts\ManagerReportPort;
use App\Platform\Shared\Enterprise\Contracts\OrganizationSubscriptionPort;
use App\Platform\Shared\Enterprise\Data\ManagerReport;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Manager learning report: org-, department-, or team-scoped. Authority is resolved from the caller's
 * ManagerScope (tenant-derived); a department/team filter is honoured ONLY when the caller's scope
 * covers it, so a manager can never report on a department or team outside their authority, and never
 * on another organization (the report is confined to the tenant org's membership regardless).
 */
class ManagerReportController extends EnterpriseController
{
    public function __construct(
        private readonly ManagerReportPort $reports,
        private readonly OrganizationSubscriptionPort $subscriptions,
        private readonly ManagerScope $managerScope,
    ) {}

    public function show(Request $request): JsonResponse
    {
        Gate::authorize('viewReports', OrganizationMember::class);

        return ApiResponse::success($this->build($request)->toArray());
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('viewReports', OrganizationMember::class);

        $report = $this->build($request)->toArray();

        return response()->streamDownload(function () use ($report): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['metric', 'value'], ',', '"', '');
            foreach ($report as $key => $value) {
                if ($key === 'seats') {
                    $seats = is_array($value) ? $value : ['purchased' => '', 'used' => '', 'available' => ''];
                    fputcsv($out, ['seats_purchased', $seats['purchased'] ?? ''], ',', '"', '');
                    fputcsv($out, ['seats_used', $seats['used'] ?? ''], ',', '"', '');
                    fputcsv($out, ['seats_available', $seats['available'] ?? ''], ',', '"', '');

                    continue;
                }
                fputcsv($out, [$key, is_scalar($value) ? $value : json_encode($value)], ',', '"', '');
            }
            fclose($out);
        }, 'manager-report.csv', ['Content-Type' => 'text/csv']);
    }

    private function build(Request $request): ManagerReport
    {
        $scope = $this->scope($request);
        $organizationId = $scope->organizationId;

        $userIds = $this->resolveUserIds($request, $scope);

        $inactiveDays = $request->integer('inactive_days', (int) config('crm.reporting.inactive_days', 30));
        $from = $request->query('from');
        $to = $request->query('to');
        $timezone = $request->query('timezone', 'UTC');

        $seatUsage = $this->subscriptions->seatSummary($organizationId);

        return $this->reports->report(
            organizationId: $organizationId,
            userIds: $userIds,
            inactiveDays: $inactiveDays,
            from: is_string($from) ? $from : null,
            to: is_string($to) ? $to : null,
            timezone: is_string($timezone) ? $timezone : 'UTC',
            seatUsage: $seatUsage === null ? null : [
                'purchased' => $seatUsage->purchased,
                'used' => $seatUsage->used,
                'available' => $seatUsage->available,
            ],
        );
    }

    /**
     * Resolve the learner user-id set the report should cover, enforcing scope coverage for any
     * requested department/team. Returns null to mean "the whole org roster" (owner/admin, unfiltered).
     *
     * @return list<int>|null
     */
    private function resolveUserIds(Request $request, ManagerScopeResult $scope): ?array
    {
        $departmentPublicId = $request->query('department_id');
        $teamPublicId = $request->query('team_id');

        if (is_string($departmentPublicId) && $departmentPublicId !== '') {
            $department = Department::where('public_id', $departmentPublicId)->first();

            if ($department === null || ! $scope->coversDepartment((int) $department->getKey())) {
                throw new NotFoundHttpException('Department not found.');
            }

            return $this->managerScope->departmentUserIds($scope->organizationId, (int) $department->getKey());
        }

        if (is_string($teamPublicId) && $teamPublicId !== '') {
            $team = Team::where('public_id', $teamPublicId)->first();

            if ($team === null || ! $scope->coversTeam((int) $team->getKey())) {
                throw new NotFoundHttpException('Team not found.');
            }

            return $this->managerScope->teamUserIds($scope->organizationId, (int) $team->getKey());
        }

        // No filter: the whole org roster for an owner/admin, or the manager's covered union otherwise.
        return $scope->viewAll ? null : $scope->userIds;
    }
}
