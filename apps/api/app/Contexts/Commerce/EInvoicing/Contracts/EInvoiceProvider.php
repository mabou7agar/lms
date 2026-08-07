<?php

declare(strict_types=1);

namespace App\Contexts\Commerce\EInvoicing\Contracts;

use App\Contexts\Commerce\EInvoicing\Data\EInvoicePayload;
use App\Contexts\Commerce\EInvoicing\Data\EInvoiceResult;

/**
 * A fiscal e-invoicing provider (ZATCA, ETA, or the fake test seam). Commerce depends only on this
 * contract; the tax-authority wire formats live entirely inside the concrete adapters.
 */
interface EInvoiceProvider
{
    public function key(): string;

    /** Submit a canonical document for clearance/reporting and return the authority's outcome. */
    public function clear(EInvoicePayload $payload): EInvoiceResult;
}
