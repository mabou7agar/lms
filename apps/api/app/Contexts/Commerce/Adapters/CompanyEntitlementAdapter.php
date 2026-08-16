<?php

namespace App\Contexts\Commerce\Adapters;

use App\Contexts\Commerce\Models\CompanyEntitlement;
use App\Contexts\Commerce\Models\CompanyEntitlementAssignment;
use App\Contexts\Commerce\Models\Product;
use App\Contexts\Commerce\Services\CompanyEntitlementService;
use App\Platform\Shared\Commerce\Contracts\CompanyEntitlementPort;
use App\Platform\Shared\Commerce\Data\CompanyAssignmentOutcome;
use App\Platform\Shared\Commerce\Data\CompanyEntitlementRef;
use App\Platform\Shared\Commerce\Data\CompanySeatHolderRef;
use App\Platform\Shared\Commerce\Data\SeatCandidate;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Commerce's implementation of CompanyEntitlementPort — the manager portal's window onto what the
 * organization bought.
 *
 * Every lookup is filtered by `organization_id` before anything else happens, so a purchase that
 * belongs to another company simply does not exist as far as this adapter is concerned. Seat
 * mechanics and policy live in CompanyEntitlementService; this class only resolves and projects.
 */
class CompanyEntitlementAdapter implements CompanyEntitlementPort
{
    public function __construct(
        private readonly CompanyEntitlementService $entitlements,
    ) {}

    /**
     * @return list<CompanyEntitlementRef>
     */
    public function forOrganization(int $organizationId): array
    {
        return CompanyEntitlement::query()
            ->forOrganization($organizationId)
            ->with(['product.courses', 'order'])
            ->latest('id')
            ->get()
            ->map(fn (CompanyEntitlement $e): CompanyEntitlementRef => $this->project($e))
            ->values()
            ->all();
    }

    public function findForOrganization(int $organizationId, string $publicId): ?CompanyEntitlementRef
    {
        $entitlement = $this->resolve($organizationId, $publicId);

        return $entitlement === null ? null : $this->project($entitlement);
    }

    /**
     * @return list<CompanySeatHolderRef>
     */
    public function seatHolders(int $organizationId, string $publicId, bool $includeRevoked = false): array
    {
        $entitlement = $this->resolve($organizationId, $publicId);

        if ($entitlement === null) {
            return [];
        }

        $query = CompanyEntitlementAssignment::query()
            ->where('company_entitlement_id', $entitlement->id)
            ->latest('id');

        if (! $includeRevoked) {
            $query->whereNull('revoked_at');
        }

        return $query->get()
            ->map(fn (CompanyEntitlementAssignment $a): CompanySeatHolderRef => new CompanySeatHolderRef(
                publicId: (string) $a->public_id,
                organizationMemberId: (int) $a->organization_member_id,
                userId: (int) $a->user_id,
                assignedAt: $a->assigned_at?->toIso8601String(),
                revokedAt: $a->revoked_at?->toIso8601String(),
                active: $a->isActive(),
            ))
            ->values()
            ->all();
    }

    /**
     * @param  list<SeatCandidate>  $candidates
     */
    public function assign(int $organizationId, string $publicId, array $candidates): CompanyAssignmentOutcome
    {
        return $this->entitlements->assign($this->resolveOrFail($organizationId, $publicId), $candidates);
    }

    public function revoke(int $organizationId, string $publicId, int $organizationMemberId): CompanyAssignmentOutcome
    {
        return $this->entitlements->revoke(
            $this->resolveOrFail($organizationId, $publicId),
            $organizationMemberId,
        );
    }

    /** Org-scoped lookup. Returning null (rather than throwing) keeps "not yours" and "not there" identical. */
    private function resolve(int $organizationId, string $publicId): ?CompanyEntitlement
    {
        return CompanyEntitlement::query()
            ->forOrganization($organizationId)
            ->where('public_id', $publicId)
            ->with(['product.courses', 'order'])
            ->first();
    }

    private function resolveOrFail(int $organizationId, string $publicId): CompanyEntitlement
    {
        $entitlement = $this->resolve($organizationId, $publicId);

        if ($entitlement === null) {
            throw new NotFoundHttpException('Purchase not found.');
        }

        return $entitlement;
    }

    private function project(CompanyEntitlement $entitlement): CompanyEntitlementRef
    {
        $product = $entitlement->getRelationValue('product');
        $order = $entitlement->getRelationValue('order');

        return new CompanyEntitlementRef(
            publicId: (string) $entitlement->public_id,
            productTitle: $product instanceof Product ? (string) $product->title : '',
            orderPublicId: $order instanceof Model ? (string) $order->getAttribute('public_id') : '',
            courses: $this->courses($product),
            seatsPurchased: $entitlement->isUnlimited() ? null : $entitlement->seats_purchased,
            seatsUsed: (int) $entitlement->seats_used,
            seatsAvailable: $entitlement->seatsAvailable(),
            status: $entitlement->effectiveStatus()->value,
            accessStartsAt: $entitlement->access_starts_at?->toIso8601String(),
            accessEndsAt: $entitlement->access_ends_at?->toIso8601String(),
            seatMode: $entitlement->seat_mode->value,
            reassignmentPolicy: $entitlement->seat_reassignment_policy->value,
            reassignmentProgressThreshold: $entitlement->reassignment_progress_threshold,
            certificateBranding: $entitlement->company_certificate_branding,
            employeeAccessExpiresWithPurchase: (bool) $entitlement->employee_access_expires_with_purchase,
            assignable: $entitlement->isAssignable(),
        );
    }

    /**
     * The bundle's courses as public id + title. Read off the eager-loaded relation generically so no
     * Catalog model is named here.
     *
     * @return list<array{id: string, title: string}>
     */
    private function courses(mixed $product): array
    {
        if (! $product instanceof Product) {
            return [];
        }

        $courses = $product->getRelationValue('courses');

        if (! is_iterable($courses)) {
            return [];
        }

        $out = [];

        foreach ($courses as $course) {
            if ($course instanceof Model) {
                $out[] = [
                    'id' => (string) $course->getAttribute('public_id'),
                    'title' => (string) $course->getAttribute('title'),
                ];
            }
        }

        return $out;
    }
}
