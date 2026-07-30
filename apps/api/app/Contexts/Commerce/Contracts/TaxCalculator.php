<?php

namespace App\Contexts\Commerce\Contracts;

use App\Contexts\Commerce\Tax\ValueObjects\TaxCalculation;

/**
 * Server-authoritative tax computation. Commerce code depends on this port; the concrete
 * TaxService looks up the applicable VAT rate by jurisdiction and produces a TaxCalculation from
 * integer minor-unit line bases. Tax is NEVER computed client-side or trusted from the request.
 */
interface TaxCalculator
{
    /**
     * @param  list<int>  $lineMinorAmounts  taxable base for each order line, in minor units
     * @param  string  $countryCode  ISO 3166-1 alpha-2 of the customer's tax jurisdiction
     * @param  bool  $pricesIncludeTax  true when the line amounts are already tax-inclusive
     */
    public function calculate(
        string $currency,
        string $countryCode,
        array $lineMinorAmounts,
        bool $pricesIncludeTax,
    ): TaxCalculation;
}
