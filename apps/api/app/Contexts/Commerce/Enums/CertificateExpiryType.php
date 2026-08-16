<?php

namespace App\Contexts\Commerce\Enums;

use Illuminate\Support\Carbon;

/**
 * How long an issued certificate stays valid. Independent of course access: a learner may lose
 * access when a subscription lapses while the certificate they already earned stays valid, which is
 * the normal expectation for a credential.
 */
enum CertificateExpiryType: string
{
    case None = 'none';
    case FixedDays = 'fixed_days';
    case FixedMonths = 'fixed_months';
    case FixedYears = 'fixed_years';
    case FixedDate = 'fixed_date';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Never expires',
            self::FixedDays => 'Fixed number of days from issue',
            self::FixedMonths => 'Fixed number of months from issue',
            self::FixedYears => 'Fixed number of years from issue',
            self::FixedDate => 'Until a specific date',
        };
    }

    public function needsValue(): bool
    {
        return in_array($this, [self::FixedDays, self::FixedMonths, self::FixedYears], true);
    }

    public function needsDate(): bool
    {
        return $this === self::FixedDate;
    }

    /** Resolve the expiry for a certificate issued at `$from`. Null means it never expires. */
    public function resolveExpiry(Carbon $from, ?int $value, ?Carbon $expiresAt): ?Carbon
    {
        return match ($this) {
            self::None => null,
            self::FixedDate => $expiresAt,
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
