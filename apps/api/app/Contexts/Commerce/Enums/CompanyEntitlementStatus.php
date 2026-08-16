<?php

namespace App\Contexts\Commerce\Enums;

/**
 * Lifecycle of a company's purchased training entitlement. `Active` is the only state that may be
 * assigned from; `Expired` is reached by the access window elapsing (evaluated against the wall
 * clock, so no scheduler is required for correctness) and `Canceled` by a refund or cancellation.
 */
enum CompanyEntitlementStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Expired => 'Expired',
            self::Canceled => 'Canceled',
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
