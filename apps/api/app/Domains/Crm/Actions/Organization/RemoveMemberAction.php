<?php

namespace App\Domains\Crm\Actions\Organization;

use App\Domains\Crm\Enums\MemberStatus;
use App\Domains\Crm\Models\OrganizationMember;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Seats\Contracts\SeatProvisioningPort;

/**
 * Removes a member from an organization and RELEASES every seat they hold. Removal and seat release
 * are one transaction so a removed employee can never keep a seat (the "deactivating an employee
 * releases their seat" policy). Seat release goes through the Shared SeatProvisioningPort — the same
 * atomic CRM seat mechanics used everywhere — so no seat bookkeeping is duplicated here.
 */
class RemoveMemberAction extends BaseAction
{
    public function __construct(private readonly SeatProvisioningPort $seats) {}

    public function execute(OrganizationMember $member): void
    {
        $this->transaction(function () use ($member): void {
            $this->seats->releaseAllSeatsForMember((int) $member->getKey());

            $member->forceFill([
                'status' => MemberStatus::Removed->value,
                'invitation_token' => null,
                'invitation_expires_at' => null,
            ])->save();
        });
    }
}
