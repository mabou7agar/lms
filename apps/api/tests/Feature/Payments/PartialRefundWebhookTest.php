<?php

use App\Contexts\Commerce\Actions\Payment\RefundOrderAction;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Payments\Gateways\FakeGateway;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Commerce/CommerceHelpers.php';

function paidOrderForPartialRefund(): Order
{
    [, $product] = courseProduct(50000);
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/cart', ['product' => $product->public_id])->assertOk();
    $checkout = test()->postJson('/api/v1/checkout')->assertCreated();
    $orderId = $checkout->json('data.order.id');
    test()->postJson("/api/v1/contracts/{$checkout->json('data.contract_id')}/accept")->assertOk();

    $payload = json_encode(['id' => 'evt_'.uniqid(), 'type' => 'payment.succeeded', 'order_reference' => $orderId]);
    test()->call('POST', '/api/v1/payment/webhook', [], [], [], ['HTTP_X-Signature' => FakeGateway::sign($payload)], $payload)->assertOk();

    return Order::where('public_id', $orderId)->firstOrFail();
}

it('keeps the order Paid when a refund.succeeded webhook settles a PARTIAL refund', function () {
    $order = paidOrderForPartialRefund();

    // Partial refund (well under the paid total) via the synchronous action → order stays Paid.
    app(RefundOrderAction::class)->execute($order, 5000);
    expect($order->fresh()->status)->toBe(OrderStatus::Paid);

    // The async confirmation webhook must NOT flip a partially-refunded order to Refunded (which
    // would revoke enrollments and mint a full credit note). Regression guard for W07.
    $payload = json_encode(['id' => 'evt_'.uniqid(), 'type' => 'refund.succeeded', 'order_reference' => $order->public_id]);
    test()->call('POST', '/api/v1/payment/webhook', [], [], [], ['HTTP_X-Signature' => FakeGateway::sign($payload)], $payload)->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Paid);
});
