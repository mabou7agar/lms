<?php

declare(strict_types=1);

namespace App\Contexts\Commerce\EInvoicing\Data;

/** Outcome of submitting a document to a tax authority (or the fake provider). */
final class EInvoiceResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $status,
        public readonly bool $accepted,
        public readonly ?string $providerReference = null,
        public readonly ?string $hash = null,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function accepted(string $status, string $reference, string $hash, array $raw = []): self
    {
        return new self($status, true, $reference, $hash, $raw);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function rejected(array $raw = []): self
    {
        return new self('rejected', false, null, null, $raw);
    }
}
