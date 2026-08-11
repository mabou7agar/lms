<?php

namespace App\Domains\Crm\Policies;

use App\Domains\Crm\Models\Team;
use App\Domains\Crm\Services\ManagerScope;
use App\Domains\Crm\Services\ManagerScopeResult;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Policies\BasePolicy;

/**
 * Team authorization for the enterprise portal. Structural changes are owner/admin only; a team
 * manager may VIEW the team(s) their scope covers.
 */
class TeamPolicy extends BasePolicy
{
    private function scope(Actor $user): ManagerScopeResult
    {
        return app(ManagerScope::class)->forActor($user);
    }

    public function viewAny(Actor $user): bool
    {
        return $this->scope($user)->isManager();
    }

    public function create(Actor $user): bool
    {
        return $this->scope($user)->viewAll;
    }

    public function view(Actor $user, Team $team): bool
    {
        return $this->scope($user)->coversTeam((int) $team->getKey());
    }

    public function update(Actor $user, Team $team): bool
    {
        return $this->scope($user)->viewAll;
    }

    public function delete(Actor $user, Team $team): bool
    {
        return $this->scope($user)->viewAll;
    }
}
