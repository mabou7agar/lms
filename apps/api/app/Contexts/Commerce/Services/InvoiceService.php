<?php

namespace App\Contexts\Commerce\Services;

use App\Contexts\Commerce\Models\Invoice;
use App\Contexts\Commerce\Models\InvoiceLine;
use App\Contexts\Commerce\Models\Order;
use App\Platform\Shared\Services\BaseService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-side builder for invoice line snapshots. buildLinesForOrder() captures the order's items
 * onto its invoice as immutable lines, apportioning the invoice's server-authoritative VAT across
 * those lines so the per-line tax sums back to the invoice tax exactly (largest-remainder, no
 * float drift).
 *
 * The operation is idempotent: an invoice that already has lines is left untouched, so it is safe
 * to call from checkout, fulfillment, or the OrderPaid listener, and safe to re-run on webhook
 * retries. It never mutates the invoice's own totals — a finalized/paid invoice stays immutable;
 * only the line snapshot is written, once.
 */
class InvoiceService extends BaseService
{
    /**
     * Snapshot the order's items onto its invoice as invoice_lines, with VAT apportioned.
     * Returns the invoice's lines (existing ones if already populated). No-op when the order has
     * no invoice or no items.
     *
     * @return Collection<int, InvoiceLine>
     */
    public function buildLinesForOrder(Order $order): Collection
    {
        $invoice = $order->getAttribute('invoice') instanceof Invoice
            ? $order->getAttribute('invoice')
            : $order->invoice()->first();

        if (! $invoice instanceof Invoice) {
            return new Collection;
        }

        return DB::transaction(function () use ($order, $invoice): Collection {
            // Idempotency: lock the invoice row and bail if lines already exist.
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->first() ?? $invoice;

            $existing = $locked->lines()->get();
            if ($existing->isNotEmpty()) {
                return $existing;
            }

            $items = $order->items()->get();
            if ($items->isEmpty()) {
                return new Collection;
            }

            // Gross weight per line = list unit price * quantity (integer minor units).
            $weights = $items->map(function ($item): int {
                $qty = max(1, (int) $item->getAttribute('quantity'));

                return (int) $item->getAttribute('unit_amount_minor') * $qty;
            })->all();

            // Apportion the ORDER-LEVEL discount (from a coupon) across the lines so the per-line net
            // sums back to the invoice's discounted subtotal — otherwise the lines total to the
            // undiscounted subtotal + tax and no longer reconcile to invoice.total_minor (and the
            // credit note issued on refund would over-credit by exactly the discount).
            $discountShares = $this->apportion((int) $order->getAttribute('discount_minor'), $weights);
            $netShares = [];
            foreach ($weights as $index => $weight) {
                $netShares[$index] = $weight - ($discountShares[$index] ?? 0);
            }

            // VAT is apportioned over the DISCOUNTED net (the base the invoice tax was computed on),
            // so per-line tax sums back to invoice.tax_minor exactly.
            $taxShares = $this->apportion((int) $locked->getAttribute('tax_minor'), $netShares);

            $lines = new Collection;
            foreach ($items->values() as $index => $item) {
                $qty = max(1, (int) $item->getAttribute('quantity'));
                $unit = (int) $item->getAttribute('unit_amount_minor');
                $net = $netShares[$index] ?? 0;
                $tax = $taxShares[$index] ?? 0;

                $lines->push($locked->lines()->create([
                    'description' => (string) $item->getAttribute('title'),
                    'quantity' => $qty,
                    // unit_amount_minor is the list price; total_minor already reflects this line's
                    // share of the order discount plus its apportioned VAT, so the lines reconcile to
                    // the invoice grand total.
                    'unit_amount_minor' => $unit,
                    'tax_minor' => $tax,
                    'total_minor' => $net + $tax,
                ]));
            }

            return $lines;
        });
    }

    /**
     * Split an integer minor-unit total across lines in proportion to their weights, using the
     * largest-remainder method so the parts sum back to $total exactly with no float rounding.
     *
     * @param  list<int>  $weights
     * @return list<int>
     */
    private function apportion(int $total, array $weights): array
    {
        $count = count($weights);
        if ($count === 0 || $total <= 0) {
            return array_fill(0, $count, 0);
        }

        $sum = array_sum($weights);
        if ($sum <= 0) {
            return array_fill(0, $count, 0);
        }

        $shares = [];
        $remainders = [];
        $allocated = 0;

        foreach ($weights as $index => $weight) {
            $exact = $total * $weight;
            $floor = intdiv($exact, $sum);
            $shares[$index] = $floor;
            $remainders[$index] = $exact - ($floor * $sum);
            $allocated += $floor;
        }

        // Hand out the leftover units to the largest remainders first.
        $leftover = $total - $allocated;
        if ($leftover > 0) {
            $order = array_keys($remainders);
            usort($order, fn (int $a, int $b) => $remainders[$b] <=> $remainders[$a] ?: $a <=> $b);

            foreach (array_slice($order, 0, $leftover) as $index) {
                $shares[$index]++;
            }
        }

        ksort($shares);

        return array_values($shares);
    }
}
