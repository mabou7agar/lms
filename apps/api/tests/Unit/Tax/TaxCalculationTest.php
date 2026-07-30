<?php

namespace Tests\Unit\Tax;

use App\Contexts\Commerce\Tax\ValueObjects\TaxCalculation;
use App\Contexts\Commerce\Tax\ValueObjects\TaxLine;
use PHPUnit\Framework\TestCase;

/**
 * Pure value-object arithmetic for the VAT tax layer. No database, no framework: exercises the
 * exclusive/inclusive line math, the net + tax = gross reconciliation invariant, and the
 * TaxCalculation aggregate (fromLines / zero / hasTax). Money is integer minor units throughout.
 */
class TaxCalculationTest extends TestCase
{
    public function test_exclusive_line_adds_tax_on_top_of_net(): void
    {
        $line = TaxLine::exclusive(10000, 1500, 'VAT 15%');

        $this->assertSame(10000, $line->netMinor);
        $this->assertSame(1500, $line->taxMinor);
        $this->assertSame(11500, $line->grossMinor);
        $this->assertSame(1500, $line->rateBps);
        $this->assertFalse($line->inclusive);
        $this->assertSame('VAT 15%', $line->label);
    }

    public function test_inclusive_line_extracts_tax_from_gross(): void
    {
        $line = TaxLine::inclusive(11500, 1500, 'VAT 15%');

        $this->assertSame(10000, $line->netMinor);
        $this->assertSame(1500, $line->taxMinor);
        $this->assertSame(11500, $line->grossMinor);
        $this->assertTrue($line->inclusive);
    }

    public function test_every_line_reconciles_net_plus_tax_equals_gross(): void
    {
        $lines = [
            TaxLine::exclusive(999, 1500, 'VAT 15%'),
            TaxLine::exclusive(333, 1500, 'VAT 15%'),
            TaxLine::inclusive(575, 1500, 'VAT 15%'),
        ];

        foreach ($lines as $line) {
            $this->assertSame(
                $line->grossMinor,
                $line->netMinor + $line->taxMinor,
                'Each tax line must satisfy net + tax = gross.',
            );
        }
    }

    public function test_from_lines_sums_each_component_and_reconciles(): void
    {
        $calc = TaxCalculation::fromLines('SAR', [
            TaxLine::exclusive(10000, 1500, 'VAT 15%'),
            TaxLine::exclusive(5000, 1500, 'VAT 15%'),
        ]);

        $this->assertSame('SAR', $calc->currency);
        $this->assertSame(15000, $calc->netMinor);
        $this->assertSame(2250, $calc->taxMinor);
        $this->assertSame(17250, $calc->grossMinor);
        $this->assertSame($calc->grossMinor, $calc->netMinor + $calc->taxMinor);
        $this->assertCount(2, $calc->lines);
        $this->assertTrue($calc->hasTax());
    }

    public function test_zero_calculation_has_no_tax_and_no_lines(): void
    {
        $calc = TaxCalculation::zero('USD');

        $this->assertSame('USD', $calc->currency);
        $this->assertSame(0, $calc->netMinor);
        $this->assertSame(0, $calc->taxMinor);
        $this->assertSame(0, $calc->grossMinor);
        $this->assertSame([], $calc->lines);
        $this->assertFalse($calc->hasTax());
    }
}
