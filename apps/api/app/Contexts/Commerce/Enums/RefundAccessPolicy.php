<?php

namespace App\Contexts\Commerce\Enums;

/**
 * What happens to course access when the purchase is refunded. Kept explicit and per-product because
 * the right answer differs by product: a self-paced course usually revokes at once, while a dated
 * cohort someone already attended is often left running to the end of the period.
 */
enum RefundAccessPolicy: string
{
    case RevokeImmediately = 'revoke_immediately';
    case KeepUntilPeriodEnd = 'keep_until_period_end';

    public function label(): string
    {
        return match ($this) {
            self::RevokeImmediately => 'Revoke access immediately',
            self::KeepUntilPeriodEnd => 'Keep access until the paid period ends',
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
