<?php

namespace App\Domains\Crm\Adapters;

use App\Domains\Crm\Enums\MemberRole;
use App\Domains\Crm\Enums\MemberStatus;
use App\Domains\Crm\Enums\OrganizationStatus;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Platform\Shared\Enterprise\Contracts\CompanyRegistrationPort;
use App\Platform\Shared\Enterprise\Data\CompanyRegistrationInput;
use App\Platform\Shared\Helpers\Slug;
use Illuminate\Support\Facades\DB;

/**
 * CRM's implementation of the Shared CompanyRegistrationPort.
 *
 * The registering user becomes the organization's OWNER, which is what makes them a manager: the
 * manager surfaces authorize through ManagerScope on an active owner/admin membership, so no Spatie
 * role is created here and none is needed. Doing it any other way would invent a second
 * authorization path alongside the one the enterprise portal already trusts.
 */
class CompanyRegistrationAdapter implements CompanyRegistrationPort
{
    public function registerCompany(CompanyRegistrationInput $input, int $ownerUserId, string $ownerEmail): int
    {
        return (int) DB::transaction(function () use ($input, $ownerUserId, $ownerEmail): int {
            $organization = Organization::create([
                'name' => $input->name,
                'slug' => $this->uniqueSlug($input->name),
                'status' => OrganizationStatus::Active->value,
                'size' => $input->size,
                'website' => $input->website,
                'country' => $input->country,
                'industry' => $input->industry,
                'phone' => $input->phone,
                'tax_id' => $input->taxId,
                'billing_address' => $input->billingAddress,
                'locale' => $input->locale,
            ]);

            OrganizationMember::create([
                'organization_id' => $organization->id,
                'user_id' => $ownerUserId,
                'email' => $ownerEmail,
                'role' => MemberRole::Owner->value,
                'status' => MemberStatus::Active->value,
                'joined_at' => now(),
            ]);

            return (int) $organization->id;
        });
    }

    /**
     * Organizations are slug-unique, and two companies may legitimately register under the same
     * trading name — suffix rather than reject the second one.
     */
    private function uniqueSlug(string $name): string
    {
        $base = Slug::make($name);
        $slug = $base;
        $suffix = 2;

        while (Organization::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
