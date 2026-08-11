<?php

namespace App\Domains\Crm\Actions\Organization;

use App\Domains\Crm\Enums\MemberStatus;
use App\Domains\Crm\Exceptions\InvalidInvitationException;
use App\Domains\Crm\Models\OrganizationMember;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Declines a membership invitation identified by its single-use token: the membership is marked
 * removed and the token cleared so it cannot be reused. Unscoped token lookup for the same reason as
 * {@see AcceptInvitationAction}.
 */
class DeclineInvitationAction extends BaseAction
{
    public function execute(string $token): void
    {
        $this->transaction(function () use ($token): void {
            $member = OrganizationMember::withoutGlobalScopes()
                ->where('invitation_token', $token)
                ->lockForUpdate()
                ->first();

            if ($member === null) {
                throw InvalidInvitationException::notFound();
            }

            if ($member->status !== MemberStatus::Invited) {
                throw InvalidInvitationException::notPending();
            }

            $member->forceFill([
                'status' => MemberStatus::Removed->value,
                'invitation_token' => null,
                'invitation_expires_at' => null,
            ])->save();
        });
    }
}
