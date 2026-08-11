<?php

namespace App\Domains\Crm\Http\Controllers\Api\V1\Enterprise;

use App\Domains\Crm\Http\Requests\Enterprise\ResizeSeatsRequest;
use App\Domains\Crm\Http\Requests\Enterprise\SeatAssignmentRequest;
use App\Domains\Crm\Http\Resources\SeatAssignmentResource;
use App\Domains\Crm\Models\OrganizationMember;
use App\Domains\Crm\Models\SeatAssignment;
use App\Domains\Crm\Models\SeatPool;
use App\Domains\Crm\Services\SeatService;
use App\Platform\Shared\Enterprise\Contracts\OrganizationSubscriptionPort;
use App\Platform\Shared\Seats\Exceptions\SeatDowngradeBelowAssignedException;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Seat management for the enterprise portal. Usage (purchased/used/available) is read from the org
 * subscription via the Commerce exposure port; assign/release run CRM's own atomic SeatService;
 * resize goes back through Commerce (downgrade-below-assigned rejected). Assignment history is a CRM
 * read. Every mutation requires owner/admin authority; usage/history are visible to any manager.
 */
class SeatController extends EnterpriseController
{
    public function __construct(
        private readonly OrganizationSubscriptionPort $subscriptions,
        private readonly SeatService $seats,
    ) {}

    public function usage(Request $request): JsonResponse
    {
        Gate::authorize('viewReports', OrganizationMember::class);

        $summary = $this->subscriptions->seatSummary($this->scope($request)->organizationId);

        return ApiResponse::success($summary?->toArray());
    }

    public function assign(SeatAssignmentRequest $request): JsonResponse
    {
        Gate::authorize('manageSeats', OrganizationMember::class);

        $organizationId = $this->scope($request)->organizationId;
        $member = $this->member((string) $request->validated()['member_id'], $organizationId);
        $pool = $this->pool($organizationId);

        $this->seats->assign($pool, $member);

        return ApiResponse::success($this->subscriptions->seatSummary($organizationId)?->toArray(), 'Seat assigned.');
    }

    public function release(SeatAssignmentRequest $request): JsonResponse
    {
        Gate::authorize('manageSeats', OrganizationMember::class);

        $organizationId = $this->scope($request)->organizationId;
        $member = $this->member((string) $request->validated()['member_id'], $organizationId);
        $pool = $this->pool($organizationId);

        // Idempotent: releasing a seat the member does not hold is a no-op (mirrors the Shared port).
        $held = SeatAssignment::query()
            ->where('seat_pool_id', $pool->getKey())
            ->where('member_id', $member->getKey())
            ->whereNull('revoked_at')
            ->exists();

        if ($held) {
            $this->seats->revoke($pool, $member);
        }

        return ApiResponse::success($this->subscriptions->seatSummary($organizationId)?->toArray(), 'Seat released.');
    }

    public function resize(ResizeSeatsRequest $request): JsonResponse
    {
        Gate::authorize('manageSeats', OrganizationMember::class);

        $organizationId = $this->scope($request)->organizationId;

        try {
            $resized = $this->subscriptions->resizeSeats($organizationId, (int) $request->validated()['seats']);
        } catch (SeatDowngradeBelowAssignedException $e) {
            return ApiResponse::error(
                'CRM_SEATS_DOWNGRADE_BELOW_ASSIGNED',
                'Cannot reduce seats below the number currently assigned.',
                ['requested' => $e->requested, 'assigned' => $e->assigned],
                409,
            );
        }

        if (! $resized) {
            return ApiResponse::error('CRM_NO_ACTIVE_SUBSCRIPTION', 'The organization has no active subscription.', [], 422);
        }

        return ApiResponse::success($this->subscriptions->seatSummary($organizationId)?->toArray(), 'Seats resized.');
    }

    public function history(Request $request): JsonResponse
    {
        Gate::authorize('viewReports', OrganizationMember::class);

        $organizationId = $this->scope($request)->organizationId;
        $poolIds = SeatPool::where('organization_id', $organizationId)->pluck('id');

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        $assignments = SeatAssignment::query()
            ->whereIn('seat_pool_id', $poolIds)
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        return ApiResponse::paginated($assignments, SeatAssignmentResource::class);
    }

    private function member(string $publicId, int $organizationId): OrganizationMember
    {
        $member = OrganizationMember::where('public_id', $publicId)
            ->where('organization_id', $organizationId)
            ->first();

        if ($member === null) {
            throw new NotFoundHttpException('Member not found.');
        }

        return $member;
    }

    private function pool(int $organizationId): SeatPool
    {
        $pool = SeatPool::where('organization_id', $organizationId)->latest('id')->first();

        if ($pool === null) {
            throw new NotFoundHttpException('No seat pool provisioned for this organization.');
        }

        return $pool;
    }
}
