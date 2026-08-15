<?php

namespace App\Domains\Crm\Ports;

use App\Domains\Crm\Enums\MemberStatus;
use App\Domains\Crm\Models\OrganizationMember;
use App\Domains\Crm\Services\ManagerScope;
use App\Platform\Shared\Enterprise\Contracts\OrgManagerCheckPort;

/**
 * CRM implementation of the Shared OrgManagerCheckPort. Reuses the SAME ManagerScope that the
 * enterprise policies authorize with — no second authorization path. For each organization the user
 * is an active member of, it asks ManagerScope whether the user is a manager (owner/admin, or a
 * department/team manager) and returns true on the first match.
 */
final class OrgManagerCheckAdapter implements OrgManagerCheckPort
{
    public function __construct(private readonly ManagerScope $scope) {}

    public function managesAnyOrganization(int $userId): bool
    {
        $organizationIds = OrganizationMember::query()
            ->where('user_id', $userId)
            ->where('status', MemberStatus::Active->value)
            ->pluck('organization_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique();

        foreach ($organizationIds as $organizationId) {
            if ($this->scope->forUser($userId, $organizationId)->isManager()) {
                return true;
            }
        }

        return false;
    }
}
