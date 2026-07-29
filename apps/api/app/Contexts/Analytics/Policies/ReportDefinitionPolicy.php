<?php

namespace App\Contexts\Analytics\Policies;

use App\Contexts\Analytics\Enums\AnalyticsPermission;
use App\Contexts\Analytics\Models\ReportDefinition;
use App\Platform\Identity\Contracts\Actor;
use App\Platform\Shared\Policies\BasePolicy;

class ReportDefinitionPolicy extends BasePolicy
{
    public function before(mixed $user, string $ability): ?bool
    {
        if ($user instanceof Actor && $user->hasRole('super_admin')) {
            return true;
        }

        return null;
    }

    public function view(Actor $user, ReportDefinition $report): bool
    {
        // The third branch used can(), which is guard-sensitive and answered false under Sanctum
        // even for a holder of the permission — so report managers silently lost access to reports
        // that were neither shared nor their own.
        return $report->visibility->value === 'shared'
            || $report->owner_id === $user->actorId()
            || $user->hasPermission(AnalyticsPermission::ManageReports->value);
    }
}
