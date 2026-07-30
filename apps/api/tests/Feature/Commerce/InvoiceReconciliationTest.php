<?php

use App\Contexts\Commerce\Enums\InvoiceStatus;
use App\Contexts\Commerce\Models\Invoice;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\Product;
use App\Contexts\Commerce\Services\InvoiceService;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('apportions the order discount so invoice lines reconcile to the invoice total', function () {
    // A coupon order: gross items 10000, 2000 discount, 400 VAT on the discounted base → grand
    // total 8400. Before the W07 fix the lines summed to the UNDISCOUNTED 10000 (+tax), so they no
    // longer reconciled to the invoice total (and the credit note over-credited on refund).
    $user = User::factory()->create();
    $product = Product::factory()->create();

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'currency' => 'SAR',
        'subtotal_minor' => 10000,
        'discount_minor' => 2000,
        'tax_minor' => 400,
        'total_minor' => 8400,
    ]);
    $order->items()->create(['product_id' => $product->id, 'title' => 'A', 'unit_amount_minor' => 6000]);
    $order->items()->create(['product_id' => $product->id, 'title' => 'B', 'unit_amount_minor' => 4000]);

    Invoice::create([
        'order_id' => $order->id,
        'number' => 'INV-2099-000999',
        'status' => InvoiceStatus::Issued->value,
        'currency' => 'SAR',
        'subtotal_minor' => 8000,
        'tax_minor' => 400,
        'total_minor' => 8400,
        'issued_at' => now(),
    ]);

    $lines = app(InvoiceService::class)->buildLinesForOrder($order->fresh());

    expect((int) $lines->sum('total_minor'))->toBe(8400)
        ->and((int) $lines->sum('tax_minor'))->toBe(400)
        ->and((int) $lines->sum('total_minor') - (int) $lines->sum('tax_minor'))->toBe(8000);
});
