<?php

namespace App\Contexts\Commerce\Actions\Payment;

use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\RefundReason;
use App\Contexts\Commerce\Enums\RefundStatus;
use App\Contexts\Commerce\Enums\TransactionStatus;
use App\Contexts\Commerce\Enums\TransactionType;
use App\Contexts\Commerce\Events\OrderRefunded;
use App\Contexts\Commerce\Exceptions\RefundNotAllowedException;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\PaymentTransaction;
use App\Contexts\Commerce\Models\Refund;
use App\Contexts\Commerce\Payments\Data\RefundRequest;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Audit\AuditLogger;
use Throwable;

/**
 * Issues a refund against a paid order — full by default, or a partial amount bounded by the
 * order's remaining refundable balance. Generalizes the original full-refund action while keeping
 * its lock-then-charge-outside-transaction shape:
 *
 *   Phase 1 (locked, COMMITS first) — lock the order, re-derive remaining refundable = paid total
 *     minus the sum of the order's NON-FAILED (pending + succeeded) refunds, validate the request,
 *     and create a pending Refund plus a pending refund PaymentTransaction. Counting in-flight
 *     pending refunds under the lock reserves capacity, so concurrent requests can never
 *     over-refund a partially-refunded order.
 *   Phase 2 (no DB transaction) — call the gateway to move the money. No network I/O ever runs
 *     inside a DB transaction. A thrown error or a declined result settles the pending refund as
 *     failed (freeing its reserved capacity) and re-raises.
 *   Phase 3 (locked) — on success settle the Refund and its transaction as succeeded, then compute
 *     whether the order is now FULLY refunded. Full refund -> order becomes Refunded (refunded_at
 *     stamped) and OrderRefunded is dispatched AFTER commit, so enrollments are revoked only on a
 *     complete refund. A partial refund leaves the order Paid and emits no domain event.
 *
 * Every refund attempt is audited. Money is integer minor units throughout; the gateway is asked
 * to refund against the original charge's provider reference.
 */
class RefundOrderAction extends BaseAction
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  int|null  $amountMinor  Minor-unit amount to refund; null refunds the full remaining balance.
     */
    public function execute(
        Order $order,
        ?int $amountMinor = null,
        RefundReason $reason = RefundReason::RequestedByCustomer,
    ): Refund {
        if ($this->statusOf($order) !== OrderStatus::Paid) {
            throw RefundNotAllowedException::notPaid((string) $order->getAttribute('public_id'));
        }

        $paidTotal = (int) $order->getAttribute('total_minor');
        $currency = (string) $order->getAttribute('currency');

        // Phase 1: validate under a lock and create the pending refund ledger. Commits first.
        $prepared = $this->transaction(function () use ($order, $amountMinor, $reason, $paidTotal, $currency): array {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($this->statusOf($locked) !== OrderStatus::Paid) {
                throw RefundNotAllowedException::notPaid((string) $locked->getAttribute('public_id'));
            }

            // Reserve against non-failed refunds so an in-flight pending refund cannot be double-spent.
            $reservedMinor = (int) Refund::where('order_id', $locked->getKey())
                ->whereIn('status', [RefundStatus::Pending->value, RefundStatus::Succeeded->value])
                ->sum('amount_minor');

            $remaining = $paidTotal - $reservedMinor;

            // A null request means "refund everything still refundable".
            $requested = $amountMinor ?? $remaining;

            if ($requested <= 0) {
                throw RefundNotAllowedException::invalidAmount($requested);
            }

            if ($requested > $remaining) {
                throw RefundNotAllowedException::exceedsRemaining($requested, $remaining);
            }

            // Locate the original charge so the gateway can find the payment to reverse.
            $charge = $locked->transactions()
                ->where('type', TransactionType::Charge->value)
                ->where('status', TransactionStatus::Succeeded->value)
                ->latest('id')
                ->first()
                ?? $locked->transactions()
                    ->where('type', TransactionType::Charge->value)
                    ->latest('id')
                    ->first();

            $providerName = $charge?->getAttribute('provider') ?? (string) config('commerce.payment.provider');
            $chargeReference = $charge?->getAttribute('provider_reference') ?? (string) $locked->getAttribute('public_id');

            $refundTxn = PaymentTransaction::create([
                'order_id' => $locked->getKey(),
                'provider' => $providerName,
                'provider_reference' => null,
                'type' => TransactionType::Refund->value,
                'status' => TransactionStatus::Pending->value,
                'amount_minor' => $requested,
                'currency' => $currency,
            ]);

            $refund = Refund::create([
                'order_id' => $locked->getKey(),
                'payment_transaction_id' => $refundTxn->getKey(),
                'amount_minor' => $requested,
                'currency' => $currency,
                'status' => RefundStatus::Pending->value,
                'reason' => $reason->value,
            ]);

            return [
                'refund' => $refund,
                'refund_transaction_id' => $refundTxn->getKey(),
                'amount_minor' => $requested,
                'charge_reference' => (string) $chargeReference,
            ];
        });

        /** @var Refund $refund */
        $refund = $prepared['refund'];
        $refundTxnId = $prepared['refund_transaction_id'];
        $requestedMinor = (int) $prepared['amount_minor'];

        // Phase 2: move the money OUTSIDE any DB transaction.
        try {
            $result = $this->gateway->refund(new RefundRequest(
                providerReference: $prepared['charge_reference'],
                amountMinor: $requestedMinor,
                currency: $currency,
            ));
        } catch (Throwable $e) {
            $this->settleFailure($order, $refund, $refundTxnId, $requestedMinor, $currency, $reason);

            throw $e;
        }

        if (! $result->isSucceeded()) {
            $this->settleFailure($order, $refund, $refundTxnId, $requestedMinor, $currency, $reason);

            throw RefundNotAllowedException::gatewayDeclined((string) $order->getAttribute('public_id'));
        }

        // Phase 3: settle success under a lock and decide full-vs-partial.
        $fullyRefunded = $this->transaction(function () use ($order, $refund, $refundTxnId, $result, $paidTotal): bool {
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            $refund->forceFill([
                'status' => RefundStatus::Succeeded->value,
                'provider_reference' => $result->providerReference,
                'processed_at' => now(),
            ])->save();

            PaymentTransaction::whereKey($refundTxnId)->update([
                'status' => TransactionStatus::Succeeded->value,
                'provider_reference' => $result->providerReference,
            ]);

            $refundedMinor = (int) Refund::where('order_id', $locked->getKey())
                ->where('status', RefundStatus::Succeeded->value)
                ->sum('amount_minor');

            $fully = $refundedMinor >= $paidTotal;

            // Full refund flips the order to Refunded; a partial refund leaves it Paid.
            if ($fully) {
                $locked->forceFill([
                    'status' => OrderStatus::Refunded->value,
                    'refunded_at' => now(),
                ])->save();
            }

            return $fully;
        });

        $this->audit->log('commerce.order.refunded', $refund, [
            'order_id' => (string) $order->getAttribute('public_id'),
            'amount_minor' => $requestedMinor,
            'currency' => $currency,
            'reason' => $reason->value,
            'provider_reference' => $result->providerReference,
            'full_refund' => $fullyRefunded,
        ]);

        // Revoke enrollments only when the order is FULLY refunded.
        if ($fullyRefunded) {
            $this->audit->log('order.refunded', $order, [
                'amount_minor' => $requestedMinor,
                'currency' => $currency,
            ]);

            OrderRefunded::dispatch($order->refresh());
        }

        return $refund;
    }

    /**
     * Settle a pending refund as failed (freeing its reserved capacity), leaving the order Paid,
     * and audit the failed attempt.
     */
    private function settleFailure(
        Order $order,
        Refund $refund,
        int|string $refundTxnId,
        int $amountMinor,
        string $currency,
        RefundReason $reason,
    ): void {
        $this->transaction(function () use ($refund, $refundTxnId): void {
            $refund->forceFill([
                'status' => RefundStatus::Failed->value,
                'processed_at' => now(),
            ])->save();

            PaymentTransaction::whereKey($refundTxnId)->update([
                'status' => TransactionStatus::Failed->value,
            ]);
        });

        $this->audit->log('commerce.order.refund_failed', $refund, [
            'order_id' => (string) $order->getAttribute('public_id'),
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'reason' => $reason->value,
        ]);
    }

    /** Derive the order's status enum without depending on a model accessor (PHPStan-clean). */
    private function statusOf(Order $order): OrderStatus
    {
        $status = $order->getAttribute('status');

        return $status instanceof OrderStatus ? $status : OrderStatus::from((string) $status);
    }
}
