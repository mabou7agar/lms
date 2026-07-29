<?php

namespace App\Contexts\Analytics\Policies;

use App\Contexts\Analytics\Enums\AnalyticsPermission;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Policies\BasePolicy;

class DashboardDefinitionPolicy extends BasePolicy
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
        // hasPermission() rather than can(): the latter resolves the request's guard, which under
        // auth:sanctum is not the `web` guard permissions are seeded against, so it answers false
        // for a genuine holder. This policy is reached from Filament (web guard, where can() would
        // work) but must not depend on which guard happens to be active.
        return $user->hasPermission(AnalyticsPermission::ViewAnalytics->value);
    }
}
