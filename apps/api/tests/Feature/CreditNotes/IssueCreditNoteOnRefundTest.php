<?php

namespace Tests\Feature\CreditNotes;

use App\Contexts\Commerce\Enums\CreditNoteStatus;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\RefundStatus;
use App\Contexts\Commerce\Events\OrderRefunded;
use App\Contexts\Commerce\Listeners\IssueCreditNoteOnRefund;
use App\Contexts\Commerce\Models\CreditNote;
use App\Contexts\Commerce\Models\Invoice;
use App\Contexts\Commerce\Models\InvoiceLine;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\Refund;
use App\Contexts\Commerce\Services\CreditNoteNumberService;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * On a fully refunded order, IssueCreditNoteOnRefund mints exactly one issued credit note that
 * mirrors the invoice's line snapshot (net + VAT per line), and is idempotent under event
 * redelivery. Money is integer minor units.
 */
class IssueCreditNoteOnRefundTest extends TestCase
{
    use RefreshDatabase;

    public function test_issues_a_credit_note_mirroring_invoice_lines(): void
    {
        $order = $this->refundedOrderWithInvoice();

        $this->listener()->handle(new OrderRefunded($order));

        $creditNote = CreditNote::where('order_id', $order->getKey())->firstOrFail();

        $this->assertSame(CreditNoteStatus::Issued, $creditNote->statusEnum());
        $this->assertSame('SAR', $creditNote->getAttribute('currency'));
        $this->assertSame(17250, $creditNote->totalMinor());
        $this->assertStringStartsWith('CN-', (string) $creditNote->getAttribute('number'));
        $this->assertCount(2, $creditNote->lines()->get());

        // Each mirrored line stores positive net + VAT magnitudes summing to the invoice line total.
        $this->assertSame(
            17250,
            (int) $creditNote->lines()->sum('amount_minor') + (int) $creditNote->lines()->sum('tax_minor'),
        );
    }

    public function test_is_idempotent_under_redelivery(): void
    {
        $order = $this->refundedOrderWithInvoice();
        $listener = $this->listener();

        $listener->handle(new OrderRefunded($order));
        $listener->handle(new OrderRefunded($order));

        $this->assertSame(1, CreditNote::where('order_id', $order->getKey())->count());
    }

    private function refundedOrderWithInvoice(): Order
    {
        $user = User::factory()->create();

        $order = Order::create([
            'user_id' => $user->id,
            'status' => OrderStatus::Refunded->value,
            'currency' => 'SAR',
            'subtotal_minor' => 15000,
            'discount_minor' => 0,
            'tax_minor' => 2250,
            'total_minor' => 17250,
            'placed_at' => now(),
        ]);
        $order->forceFill(['paid_at' => now(), 'refunded_at' => now()])->save();

        $invoice = Invoice::create([
            'order_id' => $order->getKey(),
            'number' => 'INV-2026-000001',
            'status' => 'paid',
            'currency' => 'SAR',
            'subtotal_minor' => 15000,
            'tax_minor' => 2250,
            'total_minor' => 17250,
            'issued_at' => now(),
        ]);

        InvoiceLine::create([
            'invoice_id' => $invoice->getKey(),
            'description' => 'Course A',
            'quantity' => 1,
            'unit_amount_minor' => 10000,
            'tax_minor' => 1500,
            'total_minor' => 11500,
        ]);
        InvoiceLine::create([
            'invoice_id' => $invoice->getKey(),
            'description' => 'Course B',
            'quantity' => 1,
            'unit_amount_minor' => 5000,
            'tax_minor' => 750,
            'total_minor' => 5750,
        ]);

        Refund::create([
            'order_id' => $order->getKey(),
            'amount_minor' => 17250,
            'currency' => 'SAR',
            'status' => RefundStatus::Succeeded->value,
            'reason' => 'requested_by_customer',
        ]);

        return $order;
    }

    private function listener(): IssueCreditNoteOnRefund
    {
        return new IssueCreditNoteOnRefund(app(CreditNoteNumberService::class));
    }
}
