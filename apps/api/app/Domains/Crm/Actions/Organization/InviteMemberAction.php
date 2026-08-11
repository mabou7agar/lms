<?php

namespace App\Domains\Crm\Actions\Organization;

use App\Domains\Crm\Enums\MemberStatus;
use App\Domains\Crm\Events\MemberInvited;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Platform\Identity\Contracts\UserLookupPort;
use App\Platform\Shared\Actions\BaseAction;
use Illuminate\Support\Str;

/**
 * Invites a member to an organization (idempotent per organization+email). Links an existing
 * user account if the email matches one.
 */
class InviteMemberAction extends BaseAction
{
    public function __construct(private readonly UserLookupPort $users) {}

    /** @param array<string, mixed> $data email, role? */
    public function execute(Organization $organization, array $data): OrganizationMember
    {
        [$member, $created] = $this->transaction(function () use ($organization, $data): array {
            $existing = OrganizationMember::where('organization_id', $organization->id)
                ->where('email', $data['email'])
                ->first();

            if ($existing !== null) {
                return [$existing, false];
            }

            $userId = $this->users->idByEmail($data['email']);

            $ttlDays = (int) config('crm.invitation.ttl_days', 14);

            $member = OrganizationMember::create([
                'organization_id' => $organization->id,
                'user_id' => $userId,
                'email' => $data['email'],
                'role' => $data['role'] ?? 'member',
                'status' => MemberStatus::Invited->value,
                'invited_at' => now(),
                // Single-use opaque token so the invited employee can accept/decline. Regenerated on
                // each fresh invite; cleared on accept/decline.
                'invitation_token' => Str::random(64),
                'invitation_expires_at' => $ttlDays > 0 ? now()->addDays($ttlDays) : null,
            ]);

            return [$member, true];
        });

        if ($created) {
            MemberInvited::dispatch($member);
        }

        return $member;
    }
}
