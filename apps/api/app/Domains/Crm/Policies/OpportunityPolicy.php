<?php

namespace App\Domains\Crm\Policies;

use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Policies\BasePolicy;

/**
 * Opportunities are part of the sales pipeline; they are governed by the same "manage leads"
 * permission as leads (a sales rep who works leads also works their opportunities).
 */
class OpportunityPolicy extends BasePolicy
{
    public function before(mixed $user, string $ability): ?bool
    {
        if ($user instanceof Actor && $user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    public function viewAny(Actor $user): bool
    {
        return $user->can('crm.view') || $user->can('crm.leads.manage');
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
