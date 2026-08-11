<?php

namespace App\Domains\Crm\Policies;

use App\Domains\Crm\Models\Department;
use App\Domains\Crm\Services\ManagerScope;
use App\Domains\Crm\Services\ManagerScopeResult;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Policies\BasePolicy;

/**
 * Department authorization for the enterprise portal. Structural changes (create/update/delete/assign
 * manager) are owner/admin only; a department manager may VIEW the department(s) their scope covers.
 */
class DepartmentPolicy extends BasePolicy
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

    public function view(Actor $user, Department $department): bool
    {
        return $this->scope($user)->coversDepartment((int) $department->getKey());
    }

    public function update(Actor $user, Department $department): bool
    {
        return $this->scope($user)->viewAll;
    }

    public function delete(Actor $user, Department $department): bool
    {
        return $this->scope($user)->viewAll;
    }
}
