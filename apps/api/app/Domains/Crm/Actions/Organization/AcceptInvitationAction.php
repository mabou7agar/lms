<?php

namespace App\Domains\Crm\Actions\Organization;

use App\Domains\Crm\Enums\MemberStatus;
use App\Domains\Crm\Exceptions\InvalidInvitationException;
use App\Domains\Crm\Models\OrganizationMember;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Accepts a membership invitation identified by its single-use token, linking the accepting user's
 * account to the membership and activating it.
 *
 * The token lookup runs WITHOUT the tenant global scope on purpose: the accepting user may have no
 * organization yet (or a different one), so scoping the lookup to their current tenant would hide the
 * very invitation they are accepting. The token is a 64-char unguessable single-use secret, so an
 * unscoped lookup is safe — and it is cleared on accept so it cannot be replayed.
 */
class AcceptInvitationAction extends BaseAction
{
    public function execute(string $token, int $userId): OrganizationMember
    {
        return $this->transaction(function () use ($token, $userId): OrganizationMember {
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

            $expiresAt = $member->invitation_expires_at;
            if ($expiresAt !== null && $expiresAt->isPast()) {
                throw InvalidInvitationException::expired();
            }

            $member->forceFill([
                'user_id' => $userId,
                'status' => MemberStatus::Active->value,
                'joined_at' => now(),
                'invitation_token' => null,
                'invitation_expires_at' => null,
            ])->save();

            return $member;
        });
    }
}
