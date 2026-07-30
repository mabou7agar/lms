<?php

namespace App\Contexts\Commerce\Http\Resources;

use App\Contexts\Commerce\Enums\InvoiceStatus;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\TransactionStatus;
use App\Contexts\Commerce\Enums\TransactionType;
use App\Contexts\Commerce\Models\Order;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Full order-detail read model: the order header, its line items, the invoice (with tax), a
 * summary of payment transactions, and a server-authoritative tax breakdown. All money fields
 * are integer minor units. Read-only shaping — no business logic, no persistence.
 *
 * @property Order $resource
 */
class OrderDetailResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->resource;

        $subtotal = (int) $order->getAttribute('subtotal_minor');
        $discount = (int) $order->getAttribute('discount_minor');
        $tax = (int) $order->getAttribute('tax_minor');
        $total = (int) $order->getAttribute('total_minor');
        $taxableBase = max(0, $subtotal - $discount);

        $status = $order->getAttribute('status');

        return [
            'id' => $order->getAttribute('public_id'),
            'status' => $status instanceof OrderStatus ? $status->value : (string) $status,
            'currency' => $order->getAttribute('currency'),
            'subtotal_minor' => $subtotal,
            'discount_minor' => $discount,
            'tax_minor' => $tax,
            'total_minor' => $total,
            'placed_at' => $order->getAttribute('placed_at')?->toIso8601String(),
            'paid_at' => $order->getAttribute('paid_at')?->toIso8601String(),
            'fulfilled_at' => $order->getAttribute('fulfilled_at')?->toIso8601String(),
            'refunded_at' => $order->getAttribute('refunded_at')?->toIso8601String(),

            'items' => $this->whenLoaded('items', fn () => $order->items->map(fn ($item) => [
                'id' => $item->getAttribute('public_id'),
                'title' => $item->getAttribute('title'),
                'unit_amount_minor' => (int) $item->getAttribute('unit_amount_minor'),
            ])->values()),

            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoicePayload($order->invoice)),

            'transactions' => $this->whenLoaded('transactions', fn () => $order->transactions
                ->sortByDesc('id')
                ->map(fn ($txn) => $this->transactionPayload($txn))
                ->values()),

            'tax' => [
                'taxable_base_minor' => $taxableBase,
                'tax_minor' => $tax,
                'total_minor' => $total,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function invoicePayload(?Model $invoice): ?array
    {
        if ($invoice === null) {
            return null;
        }

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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionPayload(Model $txn): array
    {
        $type = $txn->getAttribute('type');
        $status = $txn->getAttribute('status');

        return [
            'id' => $txn->getAttribute('public_id'),
            'type' => $type instanceof TransactionType ? $type->value : (string) $type,
            'status' => $status instanceof TransactionStatus ? $status->value : (string) $status,
            'provider' => $txn->getAttribute('provider'),
            'amount_minor' => (int) $txn->getAttribute('amount_minor'),
            'currency' => $txn->getAttribute('currency'),
        ];
    }
}
