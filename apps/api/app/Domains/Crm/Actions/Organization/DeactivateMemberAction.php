<?php

namespace App\Domains\Crm\Actions\Organization;

use App\Domains\Crm\Enums\MemberStatus;
use App\Domains\Crm\Models\OrganizationMember;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Seats\Contracts\SeatProvisioningPort;

/**
 * Deactivates a member (they keep their organization row but lose active status) and releases every
 * seat they hold — a deactivated employee never keeps a seat. Distinct from removal: the membership is
 * retained (status `inactive`, not `removed`) so it can be reinstated, but their seat is freed
 * immediately. Deactivation + seat release are one transaction.
 */
class DeactivateMemberAction extends BaseAction
{
    public function __construct(private readonly SeatProvisioningPort $seats) {}

    public function execute(OrganizationMember $member): void
    {
        $this->transaction(function () use ($member): void {
            $this->seats->releaseAllSeatsForMember((int) $member->getKey());

            $member->forceFill(['status' => MemberStatus::Inactive->value])->save();
        });
    }
}
