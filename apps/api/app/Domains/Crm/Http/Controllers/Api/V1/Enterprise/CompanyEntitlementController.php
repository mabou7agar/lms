<?php

namespace App\Domains\Crm\Http\Controllers\Api\V1\Enterprise;

use App\Domains\Crm\Http\Requests\Enterprise\EntitlementAssignmentRequest;
use App\Domains\Crm\Http\Requests\Enterprise\SeatRevocationRequest;
use App\Domains\Crm\Models\OrganizationMember;
use App\Domains\Crm\Services\OrganizationTargetResolver;
use App\Platform\Shared\Commerce\Contracts\CompanyEntitlementPort;
use App\Platform\Shared\Commerce\Data\CompanyEntitlementRef;
use App\Platform\Shared\Commerce\Data\CompanySeatHolderRef;
use App\Platform\Shared\Commerce\Data\SeatCandidate;
use App\Platform\Shared\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The company's training portal: what the organization has bought, and who is using it.
 *
 * Authority is the same data-driven rule as every other enterprise surface — the caller must be an
 * owner/admin of the tenant organization, checked through the manageMembers gate, and the
 * organization is taken from their resolved manager scope, never from the request. The purchase is
 * then looked up WITHIN that organization, so a manager who somehow learns another company's
 * entitlement id gets a 404, not somebody else's seats.
 *
 * The division of labour with Commerce is deliberate: this controller decides WHO (authorization and
 * resolving a member / department / team into employees, both of which are CRM's business), and
 * CompanyEntitlementPort decides WHETHER and DOES IT (seats left, expiry, reassignment policy, and
 * granting the courses). Refusals arrive as Shared exceptions that render themselves, so the manager
 * is told exactly which rule stopped them.
 */
class CompanyEntitlementController extends EnterpriseController
{
    public function __construct(
        private readonly CompanyEntitlementPort $entitlements,
        private readonly OrganizationTargetResolver $targets,
    ) {}

    /** GET /enterprise/entitlements — every purchase this organization owns. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('manageMembers', OrganizationMember::class);

        $organizationId = (int) $this->organization($request)->getKey();

        return ApiResponse::success(array_map(
            fn (CompanyEntitlementRef $ref): array => $this->present($ref),
            $this->entitlements->forOrganization($organizationId),
        ));
    }

    /** GET /enterprise/entitlements/{entitlement} — one purchase plus who currently holds its seats. */
    public function show(Request $request, string $entitlement): JsonResponse
    {
        Gate::authorize('manageMembers', OrganizationMember::class);

        $organizationId = (int) $this->organization($request)->getKey();
        $ref = $this->entitlements->findForOrganization($organizationId, $entitlement);

        if ($ref === null) {
            throw new NotFoundHttpException('Purchase not found.');
        }

        $includeRevoked = $request->boolean('include_revoked');

        return ApiResponse::success([
            ...$this->present($ref),
            'seat_holders' => $this->presentHolders(
                $this->entitlements->seatHolders($organizationId, $entitlement, $includeRevoked),
                $organizationId,
            ),
        ]);
    }

    /** POST /enterprise/entitlements/{entitlement}/assign — give the target's employees a seat each. */
    public function assign(EntitlementAssignmentRequest $request, string $entitlement): JsonResponse
    {
        Gate::authorize('manageMembers', OrganizationMember::class);

        $organizationId = (int) $this->organization($request)->getKey();
        $data = $request->validated();

        $members = $this->targets->resolve(
            (string) $data['target_type'],
            isset($data['target_id']) ? (string) $data['target_id'] : null,
            $organizationId,
        );

        // An employee with no platform account yet (invited but never signed up) cannot be enrolled,
        // so they are reported rather than silently burning a seat.
        $candidates = $members
            ->whereNotNull('user_id')
            ->map(fn (OrganizationMember $m): SeatCandidate => new SeatCandidate(
                organizationMemberId: (int) $m->getKey(),
                userId: (int) $m->getAttribute('user_id'),
            ))
            ->values()
            ->all();

        $outcome = $this->entitlements->assign($organizationId, $entitlement, $candidates);

        return ApiResponse::success([
            'target' => ['type' => (string) $data['target_type'], 'id' => $data['target_id'] ?? null],
            'summary' => [
                'matched_members' => $members->count(),
                'eligible_members' => count($candidates),
                'assigned' => $outcome->assigned,
                'already_assigned' => $outcome->alreadyAssigned,
                'skipped_without_account' => $members->whereNull('user_id')->count(),
                'courses_granted' => $outcome->coursesGranted,
            ],
            'seats' => [
                'used' => $outcome->seatsUsed,
                'available' => $outcome->seatsAvailable,
            ],
        ], 'Seats assigned.');
    }

    /** POST /enterprise/entitlements/{entitlement}/revoke — take one employee's seat back. */
    public function revoke(SeatRevocationRequest $request, string $entitlement): JsonResponse
    {
        Gate::authorize('manageMembers', OrganizationMember::class);

        $organizationId = (int) $this->organization($request)->getKey();

        $member = OrganizationMember::query()
            ->where('organization_id', $organizationId)
            ->where('public_id', (string) $request->validated()['member_id'])
            ->first();

        if ($member === null) {
            throw new NotFoundHttpException('Member not found.');
        }

        $outcome = $this->entitlements->revoke($organizationId, $entitlement, (int) $member->getKey());

        return ApiResponse::success([
            'seats' => [
                'used' => $outcome->seatsUsed,
                'available' => $outcome->seatsAvailable,
            ],
        ], 'Seat revoked.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CompanyEntitlementRef $ref): array
    {
        return [
            'id' => $ref->publicId,
            'product_title' => $ref->productTitle,
            'order_id' => $ref->orderPublicId,
            'courses' => $ref->courses,
            'seats' => [
                'purchased' => $ref->seatsPurchased,
                'used' => $ref->seatsUsed,
                'available' => $ref->seatsAvailable,
                'unlimited' => $ref->seatsPurchased === null,
            ],
            'status' => $ref->status,
            'assignable' => $ref->assignable,
            'access_starts_at' => $ref->accessStartsAt,
            'access_ends_at' => $ref->accessEndsAt,
            'policy' => [
                'seat_mode' => $ref->seatMode,
                'reassignment' => $ref->reassignmentPolicy,
                'reassignment_progress_threshold' => $ref->reassignmentProgressThreshold,
                'certificate_branding' => $ref->certificateBranding,
                'employee_access_expires_with_purchase' => $ref->employeeAccessExpiresWithPurchase,
            ],
        ];
    }

    /**
     * Seat holders, joined back to the membership rows so the portal shows employees by email rather
     * than by an internal id it has no other use for.
     *
     * @param  list<CompanySeatHolderRef>  $holders
     * @return list<array<string, mixed>>
     */
    private function presentHolders(array $holders, int $organizationId): array
    {
        $memberIds = array_map(static fn (CompanySeatHolderRef $h): int => $h->organizationMemberId, $holders);

        $members = OrganizationMember::query()
            ->where('organization_id', $organizationId)
            ->whereKey($memberIds)
            ->get()
            ->keyBy(fn (OrganizationMember $m): int => (int) $m->getKey());

        $out = [];

        foreach ($holders as $holder) {
            $member = $members->get($holder->organizationMemberId);

            $out[] = [
                'id' => $holder->publicId,
                'member_id' => $member?->getAttribute('public_id'),
                'email' => $member?->getAttribute('email'),
                'assigned_at' => $holder->assignedAt,
                'revoked_at' => $holder->revokedAt,
                'active' => $holder->active,
            ];
        }

        return $out;
    }
}
