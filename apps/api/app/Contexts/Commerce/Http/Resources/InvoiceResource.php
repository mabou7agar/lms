<?php

namespace App\Contexts\Commerce\Http\Resources;

use App\Contexts\Commerce\Enums\InvoiceStatus;
use App\Contexts\Commerce\Models\Invoice;
use App\Contexts\Commerce\Models\InvoiceLine;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Learner billing-portal read model for an invoice: number, status, currency, the net / tax /
 * gross totals, timestamps, and the immutable line snapshot. Money is integer minor units.
 *
 * @property Invoice $resource
 */
class InvoiceResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $invoice = $this->resource;
        $status = $invoice->getAttribute('status');

        return [
            'id' => $invoice->getAttribute('public_id'),
            'number' => $invoice->getAttribute('number'),
            'status' => $status instanceof InvoiceStatus ? $status->value : (string) $status,
            'currency' => $invoice->getAttribute('currency'),
            'subtotal_minor' => (int) $invoice->getAttribute('subtotal_minor'),
            'tax_minor' => (int) $invoice->getAttribute('tax_minor'),
            'total_minor' => (int) $invoice->getAttribute('total_minor'),
            'issued_at' => $invoice->getAttribute('issued_at')?->toIso8601String(),
            'paid_at' => $invoice->getAttribute('paid_at')?->toIso8601String(),

            'lines' => $this->whenLoaded('lines', fn () => $invoice->lines->map(fn (InvoiceLine $line) => [
                'id' => $line->public_id,
                'description' => $line->description,
                'quantity' => (int) $line->quantity,
                'unit_amount_minor' => (int) $line->unit_amount_minor,
                'tax_minor' => (int) $line->tax_minor,
                'total_minor' => (int) $line->total_minor,
            ])->values()),
        ];
    }
}
