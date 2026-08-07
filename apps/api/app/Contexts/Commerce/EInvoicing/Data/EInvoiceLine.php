<?php

declare(strict_types=1);

namespace App\Contexts\Commerce\EInvoicing\Data;

/** One line of a fiscal e-invoice. Money is in minor units; tax rate is a percentage (e.g. 15.0). */
final class EInvoiceLine
{
    public function __construct(
        public readonly string $description,
        public readonly int $quantity,
        public readonly int $unitPriceMinor,
        public readonly float $taxRate,
        public readonly int $taxMinor,
        public readonly int $totalMinor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => $this->unitPriceMinor,
            'tax_rate' => $this->taxRate,
            'tax' => $this->taxMinor,
            'total' => $this->totalMinor,
        ];
    }
}
