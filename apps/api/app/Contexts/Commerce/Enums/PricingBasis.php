<?php

namespace App\Contexts\Commerce\Enums;

/**
 * What the product's price means when a company buys it.
 *
 * `FixedBundlePrice` is the historical meaning and stays the default: the listed price buys the
 * whole package whatever seat count it carries. `PerSeat` reads the same listed price as the price
 * of ONE seat, so a buyer who picks ten pays ten times it.
 *
 * There is deliberately no tiered/volume basis here. Tiers need a price-break table, an admin
 * surface to edit it, and rules for what happens when a company adds seats later — none of which
 * exists, and inventing a stub enum case that no code honours would read as a supported option.
 */
enum PricingBasis: string
{
    case FixedBundlePrice = 'fixed_bundle_price';
    case PerSeat = 'per_seat';

    public function label(): string
    {
        return match ($this) {
            self::FixedBundlePrice => 'One price for the whole package',
            self::PerSeat => 'Price is per seat (multiplied by the seat count)',
        };
    }

    /** Does the chosen seat count change what the buyer pays? */
    public function scalesWithSeats(): bool
    {
        return $this === self::PerSeat;
    }

    /** The amount charged for a line, given the unit price and the seat count on it. */
    public function lineAmountMinor(int $unitAmountMinor, int $quantity): int
    {
        return $this->scalesWithSeats() ? $unitAmountMinor * max(1, $quantity) : $unitAmountMinor;
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
