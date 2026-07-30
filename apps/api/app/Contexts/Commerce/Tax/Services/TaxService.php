<?php

namespace App\Contexts\Commerce\Tax\Services;

use App\Contexts\Commerce\Contracts\TaxCalculator;
use App\Contexts\Commerce\Enums\TaxType;
use App\Contexts\Commerce\Models\TaxRate;
use App\Contexts\Commerce\Tax\ValueObjects\TaxCalculation;
use App\Contexts\Commerce\Tax\ValueObjects\TaxLine;
use App\Platform\Shared\Services\BaseService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Server-authoritative VAT computation. Resolves the active rate for the customer's jurisdiction
 * (exact country+currency first, then the country's any-currency row) and turns each line's minor
 * base into a TaxLine via the tax value objects. All arithmetic is integer minor units — never
 * float. When no rate applies (empty table or unknown jurisdiction) the result is zero tax, so the
 * platform prices correctly before any rate is seeded.
 *
 * Saudi VAT-ready (SA/SAR 15%). This computes VAT totals only; it makes no ZATCA e-invoicing
 * compliance claim.
 */
class TaxService extends BaseService implements TaxCalculator
{
    public function calculate(
        string $currency,
        string $countryCode,
        array $lineMinorAmounts,
        bool $pricesIncludeTax,
    ): TaxCalculation {
        $rate = $this->resolveRate($currency, $countryCode);

        // No applicable rate (empty table / unknown jurisdiction): tax is zero but the net still
        // passes through as the gross, so an untaxed order still totals its line amounts.
        $bps = ($rate !== null && $rate->rateBps() > 0) ? $rate->rateBps() : 0;
        $label = $this->label($bps);

        $lines = [];
        foreach ($lineMinorAmounts as $amount) {
            $lines[] = $pricesIncludeTax
                ? TaxLine::inclusive($amount, $bps, $label)
                : TaxLine::exclusive($amount, $bps, $label);
        }

        return TaxCalculation::fromLines($currency, $lines);
    }

    /**
     * The applicable active VAT rate: an exact country+currency match wins; otherwise the
     * country's any-currency (null) fallback; otherwise null (no tax).
     */
    private function resolveRate(string $currency, string $countryCode): ?TaxRate
    {
        return $this->baseQuery($countryCode)->where('currency', $currency)->first()
            ?? $this->baseQuery($countryCode)->whereNull('currency')->first();
    }

    /**
     * @return Builder<TaxRate>
     */
    private function baseQuery(string $countryCode): Builder
    {
        return TaxRate::query()
            ->where('type', TaxType::Vat->value)
            ->where('country_code', $countryCode)
            ->where('is_active', true);
    }

    /** Human label like 'VAT 15%' (or 'VAT 15.5%'), derived from basis points without float money. */
    private function label(int $bps): string
    {
        $whole = intdiv($bps, 100);
        $fraction = $bps % 100;

        $percent = $fraction === 0
            ? (string) $whole
            : rtrim(sprintf('%d.%02d', $whole, $fraction), '0');

        return 'VAT '.$percent.'%';
    }
}
