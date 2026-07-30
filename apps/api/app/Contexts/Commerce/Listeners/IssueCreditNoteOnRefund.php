<?php

namespace App\Contexts\Commerce\Listeners;

use App\Contexts\Commerce\Enums\CreditNoteStatus;
use App\Contexts\Commerce\Enums\RefundStatus;
use App\Contexts\Commerce\Events\OrderRefunded;
use App\Contexts\Commerce\Models\CreditNote;
use App\Contexts\Commerce\Models\Invoice;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\Refund;
use App\Contexts\Commerce\Services\CreditNoteNumberService;
use Illuminate\Support\Facades\DB;

/**
 * On OrderRefunded (dispatched only when an order is FULLY refunded), issue a credit note for the
 * refunded amount, mirroring the invoice's line snapshot back to the customer. The credit note's
 * lines carry the same net/tax magnitudes as the invoice lines; the credit-note document type
 * itself represents the negation against the customer ledger.
 *
 * Idempotent: the order row is locked and a credit note is issued only if one does not already
 * exist for the order, so webhook retries and re-dispatched events never mint a duplicate. When the
 * order has no invoice lines to mirror, a single fallback line for the order total is written.
 */
class IssueCreditNoteOnRefund
{
    public function __construct(
        private readonly CreditNoteNumberService $numbers,
    ) {}

    public function handle(OrderRefunded $event): void
    {
        $order = $event->order;

        DB::transaction(function () use ($order): void {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if (! $locked instanceof Order) {
                return;
            }

            // Idempotency: at most one credit note per order.
            if (CreditNote::query()->where('order_id', $locked->getKey())->exists()) {
                return;
            }

            $invoice = $locked->invoice()->first();
            $currency = (string) $locked->getAttribute('currency');

            $lines = $this->creditLinesFor($invoice instanceof Invoice ? $invoice : null);
            $total = array_sum(array_map(
                static fn (array $line): int => $line['amount_minor'] + $line['tax_minor'],
                $lines,
            ));

            // Fallback when there are no invoice lines to mirror: credit the whole order total.
            if ($lines === []) {
                $total = (int) $locked->getAttribute('total_minor');
                $lines = [[
                    'description' => 'Refund for order '.(string) $locked->getAttribute('public_id'),
                    'amount_minor' => $total,
                    'tax_minor' => 0,
                ]];
            }

            $refundId = Refund::query()
                ->where('order_id', $locked->getKey())
                ->where('status', RefundStatus::Succeeded->value)
                ->latest('id')
                ->value('id');

            $creditNote = CreditNote::create([
                'order_id' => $locked->getKey(),
                'refund_id' => $refundId,
                'number' => $this->numbers->next(),
                'status' => CreditNoteStatus::Issued->value,
                'currency' => $currency,
                'total_minor' => (int) $total,
                'issued_at' => now(),
            ]);

            foreach ($lines as $line) {
                $creditNote->lines()->create($line);
            }
        });
    }

    /**
     * Mirror the invoice's immutable line snapshot into credit-note line payloads. Each line's
     * net amount is the invoice line total minus its VAT; both are stored as positive magnitudes.
     *
     * @return list<array{description: string, amount_minor: int, tax_minor: int}>
     */
    private function creditLinesFor(?Invoice $invoice): array
    {
        if (! $invoice instanceof Invoice) {
            return [];
        }

        return $invoice->lines()->get()
            ->map(function ($line): array {
                $tax = (int) $line->getAttribute('tax_minor');
                $total = (int) $line->getAttribute('total_minor');

                return [
                    'description' => (string) $line->getAttribute('description'),
                    'amount_minor' => $total - $tax,
                    'tax_minor' => $tax,
                ];
            })
            ->values()
            ->all();
    }
}
