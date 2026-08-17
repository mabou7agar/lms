<?php

namespace Tests\Feature\Refunds;

use App\Contexts\Commerce\Actions\Payment\RefundOrderAction;
use App\Contexts\Commerce\Actions\Refund\IssueRefundAction;
use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\RefundStatus;
use App\Contexts\Commerce\Events\OrderRefunded;
use App\Contexts\Commerce\Exceptions\RefundNotAllowedException;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\PaymentTransaction;
use App\Contexts\Commerce\Models\Refund;
use App\Contexts\Commerce\Payments\Data\ChargeRequest;
use App\Contexts\Commerce\Payments\Data\ChargeResult;
use App\Contexts\Commerce\Payments\Data\RefundRequest;
use App\Contexts\Commerce\Payments\Data\RefundResult;
use App\Contexts\Commerce\Payments\Data\WebhookEvent;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Analytics\Contracts\AnalyticsEventRecorder;
use App\Platform\Shared\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * Refund domain: partial vs full refunds against a paid order, the refundable-balance guard, the
 * gateway-declined path, and financial immutability of a finalized refund. Money is integer minor
 * units; OrderRefunded fires only on a fully refunded order.
 */
class RefundFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_refund_leaves_order_paid_and_full_refund_flips_to_refunded(): void
    {
        Event::fake([OrderRefunded::class]);

        $order = $this->paidOrder(10000);
        $action = new RefundOrderAction($this->gateway(true), app(AuditLogger::class), app(AnalyticsEventRecorder::class));

        $partial = $action->execute($order, 4000);

        $this->assertSame(RefundStatus::Succeeded, $partial->statusEnum());
        $this->assertSame(4000, $partial->amountMinor());
        $this->assertSame(OrderStatus::Paid, $order->fresh()->getAttribute('status'));
        Event::assertNotDispatched(OrderRefunded::class);

        $remaining = $action->execute($order, null);

        $this->assertSame(6000, $remaining->amountMinor());
        $this->assertSame(OrderStatus::Refunded, $order->fresh()->getAttribute('status'));
        $this->assertNotNull($order->fresh()->getAttribute('refunded_at'));
        $this->assertSame(
            10000,
            (int) Refund::where('order_id', $order->getKey())
                ->where('status', RefundStatus::Succeeded->value)
                ->sum('amount_minor'),
        );
        Event::assertDispatched(OrderRefunded::class);
    }

    public function test_refund_exceeding_remaining_balance_is_rejected(): void
    {
        Event::fake([OrderRefunded::class]);

        $order = $this->paidOrder(10000);
        $action = new RefundOrderAction($this->gateway(true), app(AuditLogger::class), app(AnalyticsEventRecorder::class));

        $this->expectException(RefundNotAllowedException::class);

        $action->execute($order, 20000);
    }

    public function test_refunding_an_unpaid_order_is_rejected(): void
    {
        Event::fake([OrderRefunded::class]);

        $order = $this->paidOrder(10000);
        $order->forceFill(['status' => OrderStatus::Pending->value])->save();

        $this->expectException(RefundNotAllowedException::class);

        (new RefundOrderAction($this->gateway(true), app(AuditLogger::class), app(AnalyticsEventRecorder::class)))->execute($order);
    }

    public function test_gateway_decline_marks_refund_failed_and_keeps_order_paid(): void
    {
        Event::fake([OrderRefunded::class]);

        $order = $this->paidOrder(10000);
        $action = new IssueRefundAction(new RefundOrderAction($this->gateway(false), app(AuditLogger::class), app(AnalyticsEventRecorder::class)));

        try {
            $action->execute($order, 5000);
            $this->fail('Expected a RefundNotAllowedException when the gateway declines.');
        } catch (RefundNotAllowedException) {
            // expected
        }

        $this->assertSame(OrderStatus::Paid, $order->fresh()->getAttribute('status'));
        $this->assertSame(
            RefundStatus::Failed->value,
            Refund::where('order_id', $order->getKey())->value('status')?->value,
        );
        Event::assertNotDispatched(OrderRefunded::class);
    }

    public function test_a_finalized_refund_is_immutable(): void
    {
        $order = $this->paidOrder(10000);

        $refund = Refund::create([
            'order_id' => $order->getKey(),
            'amount_minor' => 5000,
            'currency' => 'SAR',
            'status' => RefundStatus::Pending->value,
            'reason' => 'requested_by_customer',
        ]);
        $refund->forceFill(['status' => RefundStatus::Succeeded->value])->save();

        $this->expectException(RuntimeException::class);

        $refund->update(['amount_minor' => 1]);
    }

    private function paidOrder(int $total): Order
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

    private function gateway(bool $succeeds): PaymentGateway
    {
        return new class($succeeds) implements PaymentGateway
        {
            public function __construct(public bool $succeeds) {}

            public function charge(ChargeRequest $request): ChargeResult
            {
                return new ChargeResult($request->reference, 'succeeded');
            }

            public function refund(RefundRequest $request): RefundResult
            {
                return new RefundResult(
                    'rf_'.$request->providerReference,
                    $this->succeeds ? 'succeeded' : 'failed',
                );
            }

            public function parseWebhook(string $payload, ?string $signature): WebhookEvent
            {
                return new WebhookEvent('evt', 'refund.succeeded', 'ref');
            }
        };
    }
}
