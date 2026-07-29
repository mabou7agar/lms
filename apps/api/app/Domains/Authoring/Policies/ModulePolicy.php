<?php

namespace App\Domains\Authoring\Policies;

use App\Domains\Authoring\Models\Module;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Policies\BasePolicy;
use Illuminate\Support\Facades\Gate;

class ModulePolicy extends BasePolicy
{
    public function before(mixed $user, string $ability): ?bool
    {
        if ($user instanceof Actor && $user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    public function view(Actor $user, Module $module): bool
    {
        return $this->managesCourse($user, $module);
    }

    public function update(Actor $user, Module $module): bool
    {
        return $this->managesCourse($user, $module);
    }

    public function delete(Actor $user, Module $module): bool
    {
        return $this->managesCourse($user, $module);
    }

    /**
     * Authorize through the module's parent course against the single `authoring.manage-curriculum`
     * gate, mirroring LessonPolicy — so a module in another course is denied automatically.
     */
    private function managesCourse(Actor $user, Module $module): bool
    {
        $course = $module->course;

        return $course !== null
            && Gate::forUser($user)->allows('authoring.manage-curriculum', $course);
    }
}
