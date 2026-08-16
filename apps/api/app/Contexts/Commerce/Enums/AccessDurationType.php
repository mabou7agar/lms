<?php

namespace App\Contexts\Commerce\Enums;

use Illuminate\Support\Carbon;

/**
 * How long a purchase grants access. `Lifetime` never expires; the fixed_* kinds count forward from
 * the purchase date using the product's `access_duration_value`; `FixedDate` expires for everyone on
 * the same calendar date (`access_ends_at`), which is how a cohort or a seasonal programme is sold.
 */
enum AccessDurationType: string
{
    case Lifetime = 'lifetime';
    case FixedDays = 'fixed_days';
    case FixedMonths = 'fixed_months';
    case FixedYears = 'fixed_years';
    case FixedDate = 'fixed_date';

    public function label(): string
    {
        return match ($this) {
            self::Lifetime => 'Lifetime (never expires)',
            self::FixedDays => 'Fixed number of days from purchase',
            self::FixedMonths => 'Fixed number of months from purchase',
            self::FixedYears => 'Fixed number of years from purchase',
            self::FixedDate => 'Until a specific date',
        };
    }

    /** True when the type counts forward from the purchase and needs a numeric value. */
    public function needsValue(): bool
    {
        return in_array($this, [self::FixedDays, self::FixedMonths, self::FixedYears], true);
    }

    /** True when the type needs an absolute calendar date instead of a duration. */
    public function needsDate(): bool
    {
        return $this === self::FixedDate;
    }

    /**
     * Resolve the moment access ends for a purchase made at `$from`. Null means it never expires.
     * `$value` is the product's duration value; `$endsAt` its absolute date.
     */
    public function resolveEnd(Carbon $from, ?int $value, ?Carbon $endsAt): ?Carbon
    {
        return match ($this) {
            self::Lifetime => null,
            self::FixedDate => $endsAt,
            self::FixedDays => $value === null ? null : $from->copy()->addDays($value),
            self::FixedMonths => $value === null ? null : $from->copy()->addMonths($value),
            self::FixedYears => $value === null ? null : $from->copy()->addYears($value),
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
