<?php

declare(strict_types=1);

namespace App\Contexts\Commerce\EInvoicing\Data;

/**
 * Provider-agnostic canonical representation of an invoice for fiscal e-invoicing. Building this from
 * a domain Invoice is a thin mapping step; every provider (ZATCA, ETA, fake) consumes the SAME shape,
 * so tax-authority specifics never leak into the rest of Commerce.
 *
 * `canonicalArray()` is the deterministic, ordered structure that gets hashed and submitted — the
 * cryptographic anchor the tax authority's document hash is computed over.
 */
final class EInvoicePayload
{
    /**
     * @param  array<int, EInvoiceLine>  $lines
     */
    public function __construct(
        public readonly string $invoiceNumber,
        public readonly string $issuedAt,
        public readonly string $currency,
        public readonly string $sellerName,
        public readonly ?string $sellerTaxId,
        public readonly string $buyerName,
        public readonly ?string $buyerTaxId,
        public readonly array $lines,
        public readonly int $subtotalMinor,
        public readonly int $taxMinor,
        public readonly int $totalMinor,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function canonicalArray(): array
    {
        return [
            'invoice_number' => $this->invoiceNumber,
            'issued_at' => $this->issuedAt,
            'currency' => $this->currency,
            'seller' => ['name' => $this->sellerName, 'tax_id' => $this->sellerTaxId],
            'buyer' => ['name' => $this->buyerName, 'tax_id' => $this->buyerTaxId],
            'lines' => array_map(static fn (EInvoiceLine $line): array => $line->toArray(), array_values($this->lines)),
            'totals' => ['subtotal' => $this->subtotalMinor, 'tax' => $this->taxMinor, 'total' => $this->totalMinor],
        ];
    }

    /** Base64 SHA-256 over the canonical document — the invoice hash the authority anchors on. */
    public function hash(): string
    {
        $json = (string) json_encode($this->canonicalArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return base64_encode(hash('sha256', $json, true));
    }
}
