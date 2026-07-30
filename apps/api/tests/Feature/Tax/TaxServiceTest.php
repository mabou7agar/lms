<?php

namespace Tests\Feature\Tax;

use App\Contexts\Commerce\Models\TaxRate;
use App\Contexts\Commerce\Tax\Services\TaxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Server-authoritative VAT rate resolution + computation. Verifies the jurisdiction lookup
 * (exact country+currency wins, then the country's any-currency fallback, then zero tax), the
 * is_active guard, and that the resolved rate drives exclusive vs inclusive line math. Money is
 * integer minor units.
 */
class TaxServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TaxService
    {
        return app(TaxService::class);
    }

    private function seedRate(array $attributes): TaxRate
    {
        return TaxRate::create(array_replace([
            'type' => 'vat',
            'country_code' => 'SA',
            'currency' => 'SAR',
            'rate_bps' => 1500,
            'inclusive' => false,
            'name' => 'Saudi VAT',
            'is_active' => true,
        ], $attributes));
    }

    public function test_exact_country_and_currency_rate_computes_exclusive_tax(): void
    {
        $this->seedRate(['country_code' => 'SA', 'currency' => 'SAR', 'rate_bps' => 1500]);

        $calc = $this->service()->calculate('SAR', 'SA', [10000], false);

        $this->assertSame(10000, $calc->netMinor);
        $this->assertSame(1500, $calc->taxMinor);
        $this->assertSame(11500, $calc->grossMinor);
        $this->assertSame($calc->grossMinor, $calc->netMinor + $calc->taxMinor);
        $this->assertTrue($calc->hasTax());
    }

    public function test_prices_include_tax_computes_inclusive_tax(): void
    {
        $this->seedRate(['country_code' => 'SA', 'currency' => 'SAR', 'rate_bps' => 1500]);

        $calc = $this->service()->calculate('SAR', 'SA', [11500], true);

        $this->assertSame(10000, $calc->netMinor);
        $this->assertSame(1500, $calc->taxMinor);
        $this->assertSame(11500, $calc->grossMinor);
    }

    public function test_any_currency_fallback_row_is_used_when_no_exact_match(): void
    {
        // Country-level catch-all (null currency) with no exact SAR row.
        $this->seedRate(['country_code' => 'AE', 'currency' => null, 'rate_bps' => 500, 'name' => 'UAE VAT']);

        $calc = $this->service()->calculate('USD', 'AE', [20000], false);

        $this->assertSame(20000, $calc->netMinor);
        $this->assertSame(1000, $calc->taxMinor);
        $this->assertSame(21000, $calc->grossMinor);
    }

    public function test_exact_currency_row_wins_over_any_currency_fallback(): void
    {
        $this->seedRate(['country_code' => 'SA', 'currency' => null, 'rate_bps' => 500, 'name' => 'Fallback']);
        $this->seedRate(['country_code' => 'SA', 'currency' => 'SAR', 'rate_bps' => 1500, 'name' => 'Exact']);

        $calc = $this->service()->calculate('SAR', 'SA', [10000], false);

        $this->assertSame(1500, $calc->taxMinor);
    }

    public function test_unknown_jurisdiction_yields_zero_tax(): void
    {
        $this->seedRate(['country_code' => 'SA', 'currency' => 'SAR', 'rate_bps' => 1500]);

        $calc = $this->service()->calculate('EGP', 'EG', [10000], false);

        $this->assertSame(0, $calc->taxMinor);
        $this->assertSame(10000, $calc->grossMinor);
        $this->assertFalse($calc->hasTax());
    }

    public function test_inactive_rate_is_ignored(): void
    {
        $this->seedRate(['country_code' => 'SA', 'currency' => 'SAR', 'rate_bps' => 1500, 'is_active' => false]);

        $calc = $this->service()->calculate('SAR', 'SA', [10000], false);

        $this->assertSame(0, $calc->taxMinor);
        $this->assertFalse($calc->hasTax());
    }
}
