<?php

namespace App\Contexts\Commerce\Listeners;

use App\Contexts\Commerce\Events\OrderPaid;
use App\Contexts\Commerce\Services\InvoiceService;

/**
 * On OrderPaid, snapshot the order's items onto its invoice as invoice_lines. Delegates to
 * InvoiceService, which is idempotent (an invoice that already has lines is left untouched), so
 * this is safe on webhook retries and re-dispatches. It never mutates the paid invoice's totals —
 * only the immutable line snapshot is written, once.
 */
class PopulateInvoiceLinesOnOrderPaid
{
    public function __construct(
        private readonly InvoiceService $invoices,
    ) {}

    public function handle(OrderPaid $event): void
    {
        $this->invoices->buildLinesForOrder($event->order);
    }
}
