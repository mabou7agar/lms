<?php

namespace App\Domains\Crm\Actions\Organization;

use App\Domains\Crm\Enums\MemberRole;
use App\Domains\Crm\Models\OrganizationMember;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Changes a member's role within their organization. A pure attribute change — no seat or membership
 * side effects.
 */
class ChangeMemberRoleAction extends BaseAction
{
    public function execute(OrganizationMember $member, MemberRole $role): OrganizationMember
    {
        return $this->transaction(function () use ($member, $role): OrganizationMember {
            $member->forceFill(['role' => $role->value])->save();

            return $member;
        });
    }
}
