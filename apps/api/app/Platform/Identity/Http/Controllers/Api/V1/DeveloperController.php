<?php

namespace App\Platform\Identity\Http\Controllers\Api\V1;

use App\Platform\Identity\Models\User;
use App\Platform\Shared\Enterprise\Contracts\OrganizationSubscriptionPort;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The scoped developer READ surface served under /api/v1/developer/*. Every endpoint is gated by
 * auth:sanctum + the matching Sanctum token ability (applied in the route file). Data comes ONLY
 * from Identity (the account) and the Shared OrganizationSubscriptionPort (org / seats / usage) —
 * no domain models are imported, keeping this self-contained within the Identity + Shared layers.
 */
class DeveloperController extends Controller
{
    public function __construct(private readonly OrganizationSubscriptionPort $subscriptions) {}

    public function account(Request $request): JsonResponse
    {
        $user = $this->actor($request);

        return ApiResponse::success([
            'id' => $user->getAttribute('public_id'),
            'name' => $user->getAttribute('name'),
            'email' => $user->getAttribute('email'),
            'locale' => $user->getAttribute('locale'),
            'timezone' => $user->getAttribute('timezone'),
            'organization_id' => $user->getAttribute('organization_id'),
            'created_at' => $user->created_at?->toIso8601String(),
        ]);
    }

    public function organization(Request $request): JsonResponse
    {
        $orgId = $this->organizationId($request);
        if ($orgId === null) {
            return $this->noOrganization();
        }

        $summary = $this->subscriptions->seatSummary($orgId);

        return ApiResponse::success([
            'organization_id' => $orgId,
            'subscription' => $summary === null ? null : [
                'id' => $summary->subscriptionPublicId,
                'status' => $summary->status,
            ],
        ]);
    }

    public function seats(Request $request): JsonResponse
    {
        $orgId = $this->organizationId($request);
        if ($orgId === null) {
            return $this->noOrganization();
        }

        $summary = $this->subscriptions->seatSummary($orgId);

        return ApiResponse::success([
            'organization_id' => $orgId,
            'seats' => $summary === null ? null : [
                'purchased' => $summary->purchased,
                'used' => $summary->used,
                'available' => $summary->available,
            ],
        ]);
    }

    public function usage(Request $request): JsonResponse
    {
        $orgId = $this->organizationId($request);
        if ($orgId === null) {
            return $this->noOrganization();
        }

        $summary = $this->subscriptions->seatSummary($orgId);
        $purchased = $summary?->purchased ?? 0;
        $used = $summary?->used ?? 0;

        return ApiResponse::success([
            'organization_id' => $orgId,
            'seats_purchased' => $purchased,
            'seats_used' => $used,
            'seats_available' => $summary?->available ?? 0,
            'utilization' => $purchased > 0 ? round($used / $purchased, 4) : 0.0,
        ]);
    }

    private function noOrganization(): JsonResponse
    {
        return ApiResponse::error(
            'NO_ORGANIZATION',
            'This key is not attached to an organization.',
            [],
            404,
        );
    }

    private function organizationId(Request $request): ?int
    {
        $orgId = $this->actor($request)->getAttribute('organization_id');

        return $orgId === null ? null : (int) $orgId;
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
