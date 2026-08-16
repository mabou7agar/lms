<?php

namespace App\Contexts\Commerce\Enums;

/**
 * How many seats a company purchase carries. `NotApplicable` is the correct value for a product sold
 * only to individuals. `Fixed` sells a set number set by the admin; `BuyerSelects` lets the company
 * choose a quantity at checkout; `Unlimited` covers a whole-organization licence.
 */
enum SeatMode: string
{
    case NotApplicable = 'not_applicable';
    case Fixed = 'fixed';
    case BuyerSelects = 'buyer_selects';
    case Unlimited = 'unlimited';

    public function label(): string
    {
        return match ($this) {
            self::NotApplicable => 'Not applicable (individual-only product)',
            self::Fixed => 'Fixed seat count set here',
            self::BuyerSelects => 'Company chooses the seat count at checkout',
            self::Unlimited => 'Unlimited seats for the whole organization',
        };
    }

    /** True when the admin-configured default seat count is meaningful. */
    public function needsSeatCount(): bool
    {
        return $this === self::Fixed;
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
