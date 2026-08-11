<?php

namespace App\Domains\Crm\Policies;

use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Policies\BasePolicy;

/**
 * CRM tasks (calls/emails/meetings/follow-ups) are sales activity; gated by "manage leads".
 */
class CrmTaskPolicy extends BasePolicy
{
    public function before(mixed $user, string $ability): ?bool
    {
        if ($user instanceof Actor && $user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    public function create(Actor $user): bool
    {
        return $user->can('crm.leads.manage');
    }

    public function update(Actor $user): bool
    {
        return $user->can('crm.leads.manage');
    }
}
