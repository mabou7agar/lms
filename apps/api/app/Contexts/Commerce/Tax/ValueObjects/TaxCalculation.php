<?php

namespace App\Contexts\Commerce\Tax\ValueObjects;

use InvalidArgumentException;

/**
 * Immutable aggregate of tax across an order, in integer minor units. Holds the per-line
 * breakdown plus rolled-up net/tax/gross totals for invoicing and display. Server-authoritative:
 * never reconstructed from client-supplied numbers.
 */
final readonly class TaxCalculation
{
    /**
     * @param  list<TaxLine>  $lines
     */
    public function __construct(
        public string $currency,
        public int $netMinor,
        public int $taxMinor,
        public int $grossMinor,
        public array $lines,
    ) {
        if ($netMinor + $taxMinor !== $grossMinor) {
            throw new InvalidArgumentException('net + tax must equal gross.');
        }
    }

    /**
     * Roll a set of lines up into a calculation, summing each component independently so the
     * totals always reconcile with the breakdown.
     *
     * @param  list<TaxLine>  $lines
     */
    public static function fromLines(string $currency, array $lines): self
    {
        $net = 0;
        $tax = 0;
        $gross = 0;

        foreach ($lines as $line) {
            $net += $line->netMinor;
            $tax += $line->taxMinor;
            $gross += $line->grossMinor;
        }

        return new self($currency, $net, $tax, $gross, $lines);
    }

    /** A zero calculation (nothing taxable), e.g. a fully discounted or tax-exempt order. */
    public static function zero(string $currency): self
    {
        return new self($currency, 0, 0, 0, []);
    }

    public function hasTax(): bool
    {
        return $this->taxMinor > 0;
    }
}
