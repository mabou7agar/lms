<?php

namespace App\Contexts\Commerce\Tax\ValueObjects;

use InvalidArgumentException;

/**
 * Immutable tax computation for a single taxable base, in integer minor units (never float).
 *
 * The rate is expressed in basis points (bps): 1500 bps = 15.00%. Two construction modes:
 *
 *  - exclusive: the given amount is the net (pre-tax) base; tax is added on top.
 *  - inclusive: the given amount already contains tax; net and tax are extracted from it.
 *
 * Rounding is deterministic half-up integer arithmetic so totals never drift by a minor unit and
 * results are identical across PHP builds (no float rounding-mode surprises).
 */
final readonly class TaxLine
{
    public function __construct(
        public int $netMinor,
        public int $taxMinor,
        public int $grossMinor,
        public int $rateBps,
        public bool $inclusive,
        public string $label,
    ) {
        if ($netMinor < 0 || $taxMinor < 0 || $grossMinor < 0) {
            throw new InvalidArgumentException('Tax amounts cannot be negative.');
        }

        if ($netMinor + $taxMinor !== $grossMinor) {
            throw new InvalidArgumentException('net + tax must equal gross.');
        }
    }

    /**
     * Build a line from a net (pre-tax) amount: tax is added on top.
     */
    public static function exclusive(int $netMinor, int $rateBps, string $label): self
    {
        $tax = self::roundHalfUp($netMinor * $rateBps, 10_000);

        return new self($netMinor, $tax, $netMinor + $tax, $rateBps, false, $label);
    }

    /**
     * Build a line from a tax-inclusive amount: net and tax are extracted so that
     * net = round(gross * 10000 / (10000 + rateBps)) and tax = gross - net.
     */
    public static function inclusive(int $grossMinor, int $rateBps, string $label): self
    {
        $net = self::roundHalfUp($grossMinor * 10_000, 10_000 + $rateBps);

        return new self($net, $grossMinor - $net, $grossMinor, $rateBps, true, $label);
    }

    /** Half-up integer division: round(numerator / denominator) with no float involved. */
    private static function roundHalfUp(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            throw new InvalidArgumentException('Denominator must be positive.');
        }

        return intdiv($numerator * 2 + $denominator, $denominator * 2);
    }
}
