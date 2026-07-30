<?php

namespace App\Contexts\Commerce\Enums;

/**
 * Category of a tax rate. Only VAT is supported today (Saudi VAT-ready); the enum leaves room for
 * additional MENA tax categories without a schema change.
 */
enum TaxType: string
{
    case Vat = 'vat';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
