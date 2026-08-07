<?php

declare(strict_types=1);

namespace App\Contexts\Commerce\EInvoicing\Providers;

use App\Contexts\Commerce\EInvoicing\Contracts\EInvoiceProvider;
use App\Contexts\Commerce\EInvoicing\Data\EInvoicePayload;
use App\Contexts\Commerce\EInvoicing\Data\EInvoiceResult;

/**
 * Deterministic, network-free e-invoicing provider — the local/testing seam, analogous to the payment
 * FakeGateway. It "clears" every document, echoing back the real document hash so the surrounding
 * pipeline (document persistence, status) is exercised end-to-end without a tax authority. Refused in
 * production by the manager unless explicitly permitted.
 */
final class FakeEInvoiceProvider implements EInvoiceProvider
{
    public function key(): string
    {
        return 'fake';
    }

    public function clear(EInvoicePayload $payload): EInvoiceResult
    {
        return EInvoiceResult::accepted(
            'cleared',
            'FAKE-'.substr(sha1($payload->invoiceNumber), 0, 12),
            $payload->hash(),
            ['fake' => true],
        );
    }
}
