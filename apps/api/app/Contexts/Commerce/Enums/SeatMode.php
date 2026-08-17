<?php

namespace App\Contexts\Commerce\Enums;

/**
 * How many seats a company purchase carries. `NotApplicable` is the correct value for a product sold
 * only to individuals. `Fixed` sells a set number set by the admin; `BuyerSelects` lets the company
 * choose a quantity at checkout, inside the admin's min/max/increment; `Unlimited` covers a
 * whole-organization licence; `QuoteOnly` takes the product out of self-service entirely.
 *
 * `QuoteOnly` exists so that "you cannot buy this online" is something an admin CHOOSES, not
 * something the buyer discovers because a feature is missing. Before this it was the fallback for
 * `BuyerSelects`, which meant a deliberate sales decision and an unfinished feature looked
 * identical from the outside.
 */
enum SeatMode: string
{
    case NotApplicable = 'not_applicable';
    case Fixed = 'fixed';
    case BuyerSelects = 'buyer_selects';
    case Unlimited = 'unlimited';
    case QuoteOnly = 'quote_only';

    public function label(): string
    {
        return match ($this) {
            self::NotApplicable => 'Not applicable (individual-only product)',
            self::Fixed => 'Fixed seat count set here',
            self::BuyerSelects => 'Company chooses the seat count at checkout',
            self::Unlimited => 'Unlimited seats for the whole organization',
            self::QuoteOnly => 'Not sold online — companies request a quote',
        };
    }

    /** True when the admin-configured default seat count is meaningful. */
    public function needsSeatCount(): bool
    {
        return $this === self::Fixed;
    }

    /** True when the buyer picks the seat count, so the admin's min/max/increment are meaningful. */
    public function buyerChoosesSeats(): bool
    {
        return $this === self::BuyerSelects;
    }

    /** True when the product cannot be bought self-service and must go through sales. */
    public function isQuoteOnly(): bool
    {
        return $this === self::QuoteOnly;
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
