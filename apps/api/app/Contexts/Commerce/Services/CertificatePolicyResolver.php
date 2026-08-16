<?php

namespace App\Contexts\Commerce\Services;

use App\Contexts\Commerce\Enums\BuyerType;
use App\Contexts\Commerce\Enums\CompanyEntitlementStatus;
use App\Contexts\Commerce\Models\CompanyEntitlement;
use App\Contexts\Commerce\Models\CompanyEntitlementAssignment;
use App\Contexts\Commerce\Models\OrderItem;
use App\Contexts\Commerce\Models\Product;
use App\Platform\Shared\Commerce\Data\CertificateCommercialPolicy;
use App\Platform\Shared\Enterprise\Contracts\OrganizationLookupPort;
use App\Platform\Shared\Services\BaseService;
use App\Platform\Shared\Tenancy\Contracts\TenantBrandingProvider;
use App\Platform\Shared\Tenancy\TenantId;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Works out what certificate a learner actually bought for a course they just finished.
 *
 * Two routes to a credential, and they answer differently:
 *
 *   A COMPANY SEAT wins if the learner holds one. Its terms come from the entitlement's SNAPSHOT —
 *   the policy as it stood when the company paid — so a product edited since cannot withdraw a
 *   credential the company was sold, and the company's branding follows from the same snapshot.
 *
 *   Otherwise the learner bought it themselves (or was granted it), and the LIVE product policy
 *   applies with platform branding. An individual purchase takes no snapshot, so live is the only
 *   truth there is.
 *
 * A course no active product sells is unrestricted: a certificate, no expiry, platform branding.
 * That is what free and pre-commerce courses have always done, and this must not change it.
 *
 * When several products cover the same course, the most generous wins: a learner who bought two
 * routes to a course should not lose the certificate because one of them excluded it.
 */
class CertificatePolicyResolver extends BaseService
{
    public function __construct(
        private readonly OrganizationLookupPort $organizations,
        private readonly TenantBrandingProvider $branding,
    ) {}

    public function resolve(int $userId, int $courseId, Carbon $issuedAt): CertificateCommercialPolicy
    {
        $entitlement = $this->companySeatFor($userId, $courseId);

        if ($entitlement !== null) {
            return $this->fromEntitlement($entitlement, $issuedAt);
        }

        $product = $this->personalProductFor($userId, $courseId);

        if ($product === null) {
            return CertificateCommercialPolicy::unrestricted();
        }

        if (! $product->certificate_enabled) {
            return CertificateCommercialPolicy::disabled();
        }

        return new CertificateCommercialPolicy(
            enabled: true,
            expiresAt: $product->certificateExpiresAfter($issuedAt)?->toIso8601String(),
        );
    }

    /**
     * The company purchase this learner holds a live seat in that includes the course. Expiry of the
     * ACCESS window does not disqualify it: an employee who finished the course inside the window has
     * earned the credential, and a purchase that lapses afterwards must not retroactively strip the
     * branding off it. Only a cancelled/refunded entitlement is ignored.
     */
    private function companySeatFor(int $userId, int $courseId): ?CompanyEntitlement
    {
        $entitlementIds = CompanyEntitlementAssignment::query()
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->pluck('company_entitlement_id');

        if ($entitlementIds->isEmpty()) {
            return null;
        }

        return CompanyEntitlement::query()
            ->whereKey($entitlementIds)
            ->where('status', '!=', CompanyEntitlementStatus::Canceled->value)
            ->whereHas('product.courses', fn (Builder $q): Builder => $q->whereKey($courseId))
            ->with('product')
            // A certificate-bearing purchase beats one sold without a credential.
            ->orderByDesc('certificate_enabled')
            ->orderByDesc('id')
            ->first();
    }

    private function fromEntitlement(CompanyEntitlement $entitlement, Carbon $issuedAt): CertificateCommercialPolicy
    {
        if (! $entitlement->certificate_enabled) {
            return CertificateCommercialPolicy::disabled();
        }

        $organizationId = (int) $entitlement->organization_id;
        $organization = $this->organizations->organizationRef($organizationId);
        $branding = $entitlement->company_certificate_branding;

        // Only fetch a logo when the mode actually shows one — a certificate should not carry a
        // company's mark because the record happened to have one lying around.
        $logo = $branding === 'company_logo_and_helbaron'
            ? $this->branding->brandingFor(TenantId::from($organizationId))->logoUrl
            : null;

        return new CertificateCommercialPolicy(
            enabled: true,
            expiresAt: $entitlement->certificateExpiresAfter($issuedAt)?->toIso8601String(),
            brandingMode: $branding,
            organizationId: $organizationId,
            companyName: $organization?->name,
            companyLogoUrl: $logo,
        );
    }

    /**
     * The product behind the learner's own purchase of this course. Company orders are excluded —
     * their courses reach people as seats, handled above.
     */
    private function personalProductFor(int $userId, int $courseId): ?Product
    {
        $productIds = OrderItem::query()
            ->whereHas('order', fn (Builder $q): Builder => $q
                ->where('user_id', $userId)
                ->whereNotNull('paid_at')
                ->where(fn (Builder $inner) => $inner
                    ->whereNull('buyer_type')
                    ->orWhere('buyer_type', '!=', BuyerType::Company->value)))
            ->pluck('product_id');

        if ($productIds->isEmpty()) {
            return $this->sellingProductFor($courseId);
        }

        return Product::query()
            ->whereKey($productIds)
            ->whereHas('courses', fn (Builder $q): Builder => $q->whereKey($courseId))
            ->orderByDesc('certificate_enabled')
            ->orderByDesc('id')
            ->first()
            ?? $this->sellingProductFor($courseId);
    }

    /**
     * Fallback for a learner who reached the course without buying it themselves — an enterprise
     * grant, a manual enrolment, a free course. If some ACTIVE product sells the course, its policy
     * is the one the platform advertises, so it governs. If none does, the course is not commercial
     * and nothing restricts the certificate.
     */
    private function sellingProductFor(int $courseId): ?Product
    {
        return Product::query()
            ->active()
            ->whereHas('courses', fn (Builder $q): Builder => $q->whereKey($courseId))
            ->orderByDesc('certificate_enabled')
            ->orderByDesc('id')
            ->first();
    }
}
