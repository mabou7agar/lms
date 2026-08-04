<?php

use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Events\OrderRefunded;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\PaymentTransaction;
use App\Contexts\Commerce\Payments\Gateways\FakeGateway;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/**
 * Provider-INITIATED refunds (no prior merchant refund transaction) delivered over the public
 * webhook route. Regression cover for C2: a provider-initiated PARTIAL refund must be booked at the
 * refunded amount the event carries — never the full order total — so it leaves the order Paid and
 * does not revoke enrollments. Cumulative recorded refunds are clamped to the captured total, and
 * the order only flips to Refunded (emitting OrderRefunded exactly once) when they reach it.
 */
if (! function_exists('c2ProviderRefundPaidOrder')) {
    function c2ProviderRefundPaidOrder(int $total): Order
    {
        $user = User::factory()->create();

        $order = Order::create([
            'user_id' => $user->id,
            'status' => OrderStatus::Paid->value,
            'currency' => 'SAR',
            'subtotal_minor' => $total,
            'discount_minor' => 0,
            'tax_minor' => 0,
            'total_minor' => $total,
            'placed_at' => now(),
        ]);
        $order->forceFill(['paid_at' => now()])->save();

        PaymentTransaction::create([
            'order_id' => $order->getKey(),
            'provider' => 'fake',
            'provider_reference' => 'ch_'.$order->getKey(),
            'type' => 'charge',
            'status' => 'succeeded',
            'amount_minor' => $total,
            'currency' => 'SAR',
        ]);

        return $order;
    }
}

if (! function_exists('c2PostProviderRefund')) {
    /** POST a signed provider refund webhook; $amountMinor null omits the amount entirely. */
    function c2PostProviderRefund(Order $order, ?int $amountMinor, ?string $eventId = null): void
    {
        $body = [
            'id' => $eventId ?? ('evt_'.uniqid('', true)),
            'type' => 'refund.succeeded',
            'order_reference' => $order->public_id,
        ];

        if ($amountMinor !== null) {
            $body['amount_minor'] = $amountMinor;
        }

        $payload = json_encode($body);

        test()->call(
            'POST',
            '/api/v1/payment/webhook',
            [],
            [],
            [],
            ['HTTP_X-Signature' => FakeGateway::sign($payload)],
            $payload,
        )->assertOk();
    }
}

if (! function_exists('c2RefundTxns')) {
    /** @return Collection<int, PaymentTransaction> */
    function c2RefundTxns(Order $order): Collection
    {
        return PaymentTransaction::where('order_id', $order->getKey())
            ->where('type', 'refund')
            ->get();
    }
}

if (! function_exists('c2RefundedMinor')) {
    function c2RefundedMinor(Order $order): int
    {
        return (int) PaymentTransaction::where('order_id', $order->getKey())
            ->where('type', 'refund')
            ->where('status', 'succeeded')
            ->sum('amount_minor');
    }
}

it('books a provider-initiated PARTIAL refund at the refunded amount (not the total) and keeps the order Paid', function () {
    Event::fake([OrderRefunded::class]);

    $order = c2ProviderRefundPaidOrder(50000);

    c2PostProviderRefund($order, 30000);

    expect($order->fresh()->status)->toBe(OrderStatus::Paid);

    $refunds = c2RefundTxns($order);
    expect($refunds)->toHaveCount(1);
    expect($refunds->first()->amount_minor)->toBe(30000);

    Event::assertNotDispatched(OrderRefunded::class);
});

it('flips to Refunded exactly once when a second provider-initiated partial reaches the total', function () {
    Event::fake([OrderRefunded::class]);

    $order = c2ProviderRefundPaidOrder(50000);

    c2PostProviderRefund($order, 30000);
    expect($order->fresh()->status)->toBe(OrderStatus::Paid);

    c2PostProviderRefund($order, 20000);
    expect($order->fresh()->status)->toBe(OrderStatus::Refunded);

    expect(c2RefundedMinor($order))->toBe(50000);
    Event::assertDispatchedTimes(OrderRefunded::class, 1);
});

it('clamps a provider-initiated over-refund so recorded refunds never exceed the total', function () {
    Event::fake([OrderRefunded::class]);

    $order = c2ProviderRefundPaidOrder(50000);

    // First partial leaves 20000 refundable.
    c2PostProviderRefund($order, 30000);
    expect($order->fresh()->status)->toBe(OrderStatus::Paid);

    // The provider now claims far more than remains; it must clamp to the 20000 balance.
    c2PostProviderRefund($order, 999999);

    expect(c2RefundedMinor($order))->toBe(50000);
    expect($order->fresh()->status)->toBe(OrderStatus::Refunded);

    $max = (int) c2RefundTxns($order)->max('amount_minor');
    expect($max)->toBeLessThanOrEqual(50000);
    Event::assertDispatchedTimes(OrderRefunded::class, 1);
});

it('treats a duplicate provider refund webhook (same event id) as a no-op', function () {
    Event::fake([OrderRefunded::class]);

    $order = c2ProviderRefundPaidOrder(50000);

    c2PostProviderRefund($order, 30000, 'evt-c2-dup');
    c2PostProviderRefund($order, 30000, 'evt-c2-dup');

    expect(c2RefundTxns($order))->toHaveCount(1);
    expect(c2RefundedMinor($order))->toBe(30000);
    expect($order->fresh()->status)->toBe(OrderStatus::Paid);
    Event::assertNotDispatched(OrderRefunded::class);
});

it('flips to Refunded when a provider-initiated refund equals the full total', function () {
    Event::fake([OrderRefunded::class]);

    $order = c2ProviderRefundPaidOrder(50000);

    c2PostProviderRefund($order, 50000);

    expect($order->fresh()->status)->toBe(OrderStatus::Refunded);
    expect(c2RefundedMinor($order))->toBe(50000);
    Event::assertDispatchedTimes(OrderRefunded::class, 1);
});

it('records the full total for an amount-less provider refund with no prior refund row (legacy behavior)', function () {
    Event::fake([OrderRefunded::class]);

    $order = c2ProviderRefundPaidOrder(50000);

    c2PostProviderRefund($order, null);

    expect($order->fresh()->status)->toBe(OrderStatus::Refunded);
    expect(c2RefundedMinor($order))->toBe(50000);
    Event::assertDispatchedTimes(OrderRefunded::class, 1);
});
