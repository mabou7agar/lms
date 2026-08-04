<?php

namespace Tests\Feature\Payments;

use App\Contexts\Commerce\Actions\Payment\ProcessWebhookAction;
use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\RefundStatus;
use App\Contexts\Commerce\Enums\TransactionStatus;
use App\Contexts\Commerce\Events\OrderRefunded;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\PaymentTransaction;
use App\Contexts\Commerce\Models\Refund;
use App\Contexts\Commerce\Payments\Data\ChargeRequest;
use App\Contexts\Commerce\Payments\Data\ChargeResult;
use App\Contexts\Commerce\Payments\Data\RefundRequest;
use App\Contexts\Commerce\Payments\Data\RefundResult;
use App\Contexts\Commerce\Payments\Data\WebhookEvent;
use App\Contexts\Commerce\Payments\GatewayManager;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * ProcessWebhookAction refund handling + webhook idempotency. A refund.succeeded event settles the
 * refund ledger and flips the order to refunded exactly once; a redelivery of the same provider
 * event id (dedup via PaymentWebhookEvent) is a no-op. The gateway is stubbed so signature parsing
 * is deterministic — signature verification itself is covered in the adapter's own unit test.
 */
class ProcessWebhookRefundTest extends TestCase
{
    use RefreshDatabase;

    public function test_refund_succeeded_settles_ledger_and_refunds_order(): void
    {
        Event::fake([OrderRefunded::class]);

        [$order, $refund] = $this->paidOrderWithPendingRefund(10000);
        $action = $this->action($order->public_id, 'evt-refund-1');

        $action->execute('{}', null, null);

        $this->assertSame(OrderStatus::Refunded, $order->fresh()->getAttribute('status'));
        $this->assertSame(RefundStatus::Succeeded, $refund->fresh()->statusEnum());
        $this->assertSame(
            TransactionStatus::Succeeded->value,
            PaymentTransaction::where('order_id', $order->getKey())
                ->where('type', 'refund')->value('status')?->value,
        );
        $this->assertDatabaseHas('payment_webhook_events', ['event_id' => 'evt-refund-1']);
        Event::assertDispatched(OrderRefunded::class);
    }

    public function test_replayed_event_id_is_deduplicated_and_does_not_re_emit(): void
    {
        Event::fake([OrderRefunded::class]);

        [$order] = $this->paidOrderWithPendingRefund(10000);
        $action = $this->action($order->public_id, 'evt-refund-1');

        $action->execute('{}', null, null);
        $action->execute('{}', null, null);

        $this->assertSame(1, PaymentTransaction::where('order_id', $order->getKey())
            ->where('type', 'refund')->count());
        $this->assertSame(OrderStatus::Refunded, $order->fresh()->getAttribute('status'));
        Event::assertDispatchedTimes(OrderRefunded::class, 1);
    }

    /**
     * @return array{0: Order, 1: Refund}
     */
    private function paidOrderWithPendingRefund(int $total): array
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

        $txn = PaymentTransaction::create([
            'order_id' => $order->getKey(),
            'provider' => 'fake',
            'provider_reference' => null,
            'type' => 'refund',
            'status' => 'pending',
            'amount_minor' => $total,
            'currency' => 'SAR',
        ]);

        $refund = Refund::create([
            'order_id' => $order->getKey(),
            'payment_transaction_id' => $txn->getKey(),
            'amount_minor' => $total,
            'currency' => 'SAR',
            'status' => RefundStatus::Pending->value,
            'reason' => 'requested_by_customer',
        ]);

        return [$order, $refund];
    }

    private function action(string $orderReference, string $eventId): ProcessWebhookAction
    {
        $event = new WebhookEvent(
            id: $eventId,
            type: 'refund.succeeded',
            orderReference: $orderReference,
            providerReference: 'rf_provider_ref',
            raw: [],
        );

        $gateway = new class($event) implements PaymentGateway
        {
            public function __construct(private WebhookEvent $event) {}

            public function charge(ChargeRequest $request): ChargeResult
            {
                return new ChargeResult($request->reference, 'pending');
            }

            public function refund(RefundRequest $request): RefundResult
            {
                return new RefundResult($request->providerReference, 'succeeded');
            }

            public function parseWebhook(string $payload, ?string $signature): WebhookEvent
            {
                return $this->event;
            }
        };

        $gateways = new class($gateway) extends GatewayManager
        {
            public function __construct(private PaymentGateway $gateway) {}

            public function resolve(): PaymentGateway
            {
                return $this->gateway;
            }

            public function resolveProvider(string $provider): PaymentGateway
            {
                return $this->gateway;
            }
        };

        return new ProcessWebhookAction($gateways);
    }
}
