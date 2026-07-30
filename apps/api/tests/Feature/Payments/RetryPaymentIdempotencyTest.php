<?php

use App\Contexts\Commerce\Actions\Payment\InitiatePaymentAction;
use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\TransactionStatus;
use App\Contexts\Commerce\Enums\TransactionType;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Payments\Data\ChargeRequest;
use App\Contexts\Commerce\Payments\Data\ChargeResult;
use App\Contexts\Commerce\Payments\Data\RefundRequest;
use App\Contexts\Commerce\Payments\Data\RefundResult;
use App\Contexts\Commerce\Payments\Data\WebhookEvent;
use App\Contexts\Commerce\Payments\Recovery\PaymentRecoveryService;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Commerce/CommerceHelpers.php';

/**
 * Recording gateway double: captures every ChargeRequest so the test can assert the dunning-retry
 * idempotency metadata that protects against double-charging.
 */
function recordingGateway(): PaymentGateway
{
    return new class implements PaymentGateway
    {
        /** @var list<ChargeRequest> */
        public array $charges = [];

        public function charge(ChargeRequest $request): ChargeResult
        {
            $this->charges[] = $request;

            return new ChargeResult(providerReference: 'rec_'.count($this->charges), status: 'pending');
        }

        public function refund(RefundRequest $request): RefundResult
        {
            return new RefundResult(providerReference: 're_x', status: 'succeeded');
        }

        public function parseWebhook(string $payload, ?string $signature): WebhookEvent
        {
            return new WebhookEvent(id: 'x', type: 'payment.succeeded', orderReference: '', providerReference: null, raw: []);
        }
    };
}

/** Create a pending order via the real checkout path (default fake gateway), then mark it Failed. */
function failedOrderForRetry(): Order
{
    [, $product] = courseProduct();
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    test()->postJson('/api/v1/cart', ['product' => $product->public_id])->assertOk();
    $checkout = test()->postJson('/api/v1/checkout')->assertCreated();
    $order = Order::where('public_id', $checkout->json('data.order.id'))->firstOrFail();
    $order->forceFill(['status' => OrderStatus::Failed->value])->save();

    return $order->fresh();
}

it('charges with an idempotency key and records a pending transaction on retry', function () {
    $order = failedOrderForRetry();

    $gateway = recordingGateway();
    app()->instance(PaymentGateway::class, $gateway);

    $result = app(InitiatePaymentAction::class)->execute($order);

    expect($result->status)->toBe('pending')
        ->and($gateway->charges)->toHaveCount(1);

    $sent = $gateway->charges[0];
    expect($sent->idempotencyKey)->toBe($order->public_id.':r1')
        ->and($sent->reference)->toBe($order->public_id)
        ->and($sent->amountMinor)->toBe((int) $order->getAttribute('total_minor'));

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($order->fresh()->transactions()
            ->where('type', TransactionType::Charge->value)
            ->where('status', TransactionStatus::Pending->value)
            ->count())->toBeGreaterThanOrEqual(1);
});

it('reuses the same idempotency key for a duplicate retry of the same attempt', function () {
    $order = failedOrderForRetry();

    $gateway = recordingGateway();
    app()->instance(PaymentGateway::class, $gateway);

    // Two initiations with no PaymentAttempt rows recorded between them represent a duplicated or
    // concurrent retry of the SAME attempt: the provider-side key must be identical so the card is
    // charged at most once.
    app(InitiatePaymentAction::class)->execute($order->fresh());
    app(InitiatePaymentAction::class)->execute($order->fresh());

    expect($gateway->charges)->toHaveCount(2)
        ->and($gateway->charges[0]->idempotencyKey)->toBe($gateway->charges[1]->idempotencyKey);
});

it('advances the idempotency key for a genuinely new dunning attempt', function () {
    $order = failedOrderForRetry();

    $gateway = recordingGateway();
    app()->instance(PaymentGateway::class, $gateway);

    // The dunning worker records a PaymentAttempt for the previous (failed) try. The NEXT charge is
    // therefore a distinct attempt and MUST carry a distinct key, or the provider would dedupe and
    // refuse a legitimately new charge.
    app(PaymentRecoveryService::class)->record($order, new ChargeResult(providerReference: 'x', status: 'failed'));

    app(InitiatePaymentAction::class)->execute($order->fresh());

    expect($gateway->charges[0]->idempotencyKey)->toBe($order->public_id.':r2');
});
