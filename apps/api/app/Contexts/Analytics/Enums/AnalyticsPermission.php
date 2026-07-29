<?php

namespace App\Contexts\Analytics\Enums;

use App\Platform\Shared\Analytics\AnalyticsAccess;

enum AnalyticsPermission: string
{
    case ViewAnalytics = AnalyticsAccess::VIEW;
    case ManageReports = AnalyticsAccess::MANAGE_REPORTS;
    case ExportAnalytics = AnalyticsAccess::EXPORT;

    /**
     * Currency-denominated metrics, gated separately from ViewAnalytics so a role can read
     * engagement figures without being handed platform revenue. Instructors hold ViewAnalytics
     * but not this.
     */
    case ViewRevenue = AnalyticsAccess::VIEW_REVENUE;

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
