<?php

namespace App\Contexts\Commerce\Enums;

/**
 * Recurring billing cadence for a subscription plan. `months()` returns the number of calendar
 * months to advance the current-period end on each successful renewal.
 */
enum BillingInterval: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SemiAnnual = 'semiannual';
    case Annual = 'annual';

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::SemiAnnual => 6,
            self::Annual => 12,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
