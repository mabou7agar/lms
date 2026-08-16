<?php

namespace App\Contexts\Commerce\Enums;

/**
 * Who a purchase belongs to. An individual buys for themselves and is enrolled directly; a company
 * buys on behalf of an organization, which receives seats its manager distributes.
 *
 * This is deliberately separate from ProductAudience: the audience says who a product MAY be sold
 * to, while the buyer type says who is actually buying. Checkout matches the two.
 */
enum BuyerType: string
{
    case Individual = 'individual';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Individual => 'Individual',
            self::Company => 'Company',
        };
    }

    public function isCompany(): bool
    {
        return $this === self::Company;
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
