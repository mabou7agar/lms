<?php

namespace App\Domains\Crm\Adapters;

use App\Domains\Crm\Enums\MemberRole;
use App\Domains\Crm\Enums\MemberStatus;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Platform\Shared\Enterprise\Contracts\OrganizationLookupPort;
use App\Platform\Shared\Enterprise\Data\OrganizationRef;

/**
 * CRM's implementation of the Shared OrganizationLookupPort.
 *
 * "May purchase for" deliberately means owner or admin — the same authority ManagerScope treats as
 * whole-organization control. A department or team manager runs part of the org but does not commit
 * it to a purchase, and a plain member certainly does not.
 */
class OrganizationLookupAdapter implements OrganizationLookupPort
{
    public function managedOrganizationIdFor(int $userId): ?int
    {
        $membership = OrganizationMember::query()
            ->where('user_id', $userId)
            ->where('status', MemberStatus::Active->value)
            ->whereIn('role', [MemberRole::Owner->value, MemberRole::Admin->value])
            ->orderBy('id')
            ->first();

        return $membership === null ? null : (int) $membership->getAttribute('organization_id');
    }

    public function organizationRef(int $organizationId): ?OrganizationRef
    {
        $organization = Organization::query()->whereKey($organizationId)->first();

        if ($organization === null) {
            return null;
        }

        return new OrganizationRef(
            id: (int) $organization->getKey(),
            publicId: (string) $organization->getAttribute('public_id'),
            name: (string) $organization->getAttribute('name'),
            country: $organization->getAttribute('country'),
            phone: $organization->getAttribute('phone'),
            taxId: $organization->getAttribute('tax_id'),
            billingAddress: $organization->getAttribute('billing_address'),
        );
    }

    /**
     * @return list<int>
     */
    public function managerUserIds(int $organizationId): array
    {
        return OrganizationMember::query()
            ->where('organization_id', $organizationId)
            ->where('status', MemberStatus::Active->value)
            ->whereIn('role', [MemberRole::Owner->value, MemberRole::Admin->value])
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
