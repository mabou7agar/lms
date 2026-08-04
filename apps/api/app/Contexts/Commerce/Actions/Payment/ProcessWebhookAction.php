<?php

namespace App\Contexts\Commerce\Actions\Payment;

use App\Contexts\Commerce\Enums\InvoiceStatus;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\RefundStatus;
use App\Contexts\Commerce\Enums\TransactionStatus;
use App\Contexts\Commerce\Enums\TransactionType;
use App\Contexts\Commerce\Events\OrderPaid;
use App\Contexts\Commerce\Events\OrderRefunded;
use App\Contexts\Commerce\Events\PaymentFailed;
use App\Contexts\Commerce\Exceptions\WebhookSignatureException;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\PaymentTransaction;
use App\Contexts\Commerce\Models\PaymentWebhookEvent;
use App\Contexts\Commerce\Models\Refund;
use App\Contexts\Commerce\Payments\Data\WebhookEvent;
use App\Contexts\Commerce\Payments\GatewayManager;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Verifies + parses a provider webhook and advances the order state exactly once. Handles
 * payment.succeeded, payment.failed and refund.succeeded, dispatching OrderPaid, PaymentFailed
 * and OrderRefunded respectively.
 *
 * Idempotency is layered: the provider event id is deduplicated via PaymentWebhookEvent (a unique
 * event_id makes a replayed delivery a no-op), the order row is locked for the whole transition,
 * and every state change is guarded on the order's current status so the same effect can never be
 * applied twice. processed_at is stamped exactly once per accepted event, inside the same
 * transaction as the state change. No gateway I/O runs inside the DB transaction — only the parse
 * (signature verification) happens up front, in the adapter.
 *
 * Per-provider routing: when a provider slug is supplied (the /payment/webhook/{provider} route)
 * the matching adapter is resolved via the GatewayManager; otherwise the config-selected default
 * gateway is used (the legacy /payment/webhook route). Either way the signature is verified INSIDE
 * the adapter, which fails closed on a missing/invalid signature.
 */
class ProcessWebhookAction extends BaseAction
{
    public function __construct(private readonly GatewayManager $gateways) {}

    public function execute(string $payload, ?string $signature, ?string $provider = null): void
    {
        // Resolve the provider-specific adapter, or the config default when no provider is routed.
        // parseWebhook verifies the signature and throws on a missing/invalid one (fail closed).
        $gateway = $provider !== null
            ? $this->gateways->resolveProvider($provider)
            : $this->gateways->resolve();

        $event = $gateway->parseWebhook($payload, $signature);

        // A provider event with no id cannot be safely deduplicated; fail closed rather than let a
        // blank id collapse every such event into a single processed row.
        if ($event->id === '') {
            throw new WebhookSignatureException('Webhook event is missing an id.');
        }

        $providerName = $provider ?? (string) config('commerce.payment.provider');

        // The domain event to emit AFTER the transaction commits, or null when nothing to emit.
        // Shape: array{type: 'paid'|'failed'|'refunded', order: Order}.
        $outcome = $this->transaction(function () use ($event, $payload, $providerName): ?array {
            // Dedup by provider event id: a replayed delivery finds the row already processed.
            $record = PaymentWebhookEvent::firstOrCreate(
                ['event_id' => $event->id],
                [
                    'provider' => $providerName,
                    'type' => $event->type,
                    'payload' => json_decode($payload, true),
                ],
            );

            if ($record->processed_at !== null) {
                return null; // already handled — replays are a no-op
            }

            $order = Order::where('public_id', $event->orderReference)->lockForUpdate()->first();

            $outcome = $order !== null ? $this->apply($order, $event, $providerName) : null;

            // Stamp processed_at exactly once, for every accepted event (even unknown types or an
            // unmatched order), so a redelivery is always a no-op.
            $record->forceFill(['processed_at' => now()])->save();

            return $outcome;
        });

        if ($outcome === null) {
            return;
        }

        match ($outcome['type']) {
            'paid' => OrderPaid::dispatch($outcome['order']),
            'failed' => PaymentFailed::dispatch($outcome['order']),
            'refunded' => OrderRefunded::dispatch($outcome['order']),
            default => null,
        };
    }

    /**
     * Apply one webhook event to the locked order and return the domain event to emit (or null).
     *
     * @return array{type: string, order: Order}|null
     */
    private function apply(Order $order, WebhookEvent $event, string $providerName): ?array
    {
        if ($event->type === 'payment.succeeded' && $order->getAttribute('status') === OrderStatus::Pending) {
            $order->forceFill(['status' => OrderStatus::Paid->value, 'paid_at' => now()])->save();

            // Settle exactly ONE charge as succeeded — the one this webhook confirms (matched by
            // provider reference, else the newest). Earlier still-pending charge rows accumulated by
            // dunning retries are superseded (failed), so the ledger never shows N succeeded charge
            // rows for a single real payment.
            $ref = $event->providerReference;
            $target = $order->transactions()
                ->where('type', TransactionType::Charge->value)
                ->when($ref !== null, fn ($q) => $q->where('provider_reference', $ref))
                ->latest('id')
                ->first()
                ?? $order->transactions()
                    ->where('type', TransactionType::Charge->value)
                    ->latest('id')
                    ->first();

            if ($target !== null) {
                $target->forceFill([
                    'status' => TransactionStatus::Succeeded->value,
                    'provider_reference' => $target->getAttribute('provider_reference') ?? $ref,
                ])->save();

                $order->transactions()
                    ->where('type', TransactionType::Charge->value)
                    ->where('status', TransactionStatus::Pending->value)
                    ->whereKeyNot($target->getKey())
                    ->update(['status' => TransactionStatus::Failed->value]);
            }

            $order->invoice?->forceFill(['status' => InvoiceStatus::Paid->value, 'paid_at' => now()])->save();

            return ['type' => 'paid', 'order' => $order];
        }

        if ($event->type === 'payment.failed' && $order->getAttribute('status') === OrderStatus::Pending) {
            $order->forceFill(['status' => OrderStatus::Failed->value])->save();
            $order->transactions()
                ->where('type', TransactionType::Charge->value)
                ->update(['status' => TransactionStatus::Failed->value]);

            return ['type' => 'failed', 'order' => $order];
        }

        if ($event->type === 'refund.succeeded') {
            return $this->applyRefund($order, $event, $providerName);
        }

        return null;
    }

    /**
     * Confirm an asynchronous refund and settle the refund ledger, then move the order to Refunded
     * ONLY when the cumulative succeeded refund reaches the captured total. A partial refund settles
     * the ledger but leaves the order Paid (mirroring RefundOrderAction phase 3), so enrollments are
     * never revoked and no credit note is minted for a partial.
     *
     * Two ledger shapes are handled:
     *   - A refund WE initiated (RefundOrderAction) leaves a PENDING refund transaction; this event
     *     is its async confirmation, so we settle that pending row succeeded.
     *   - A provider-INITIATED refund has no pending row; we record a NEW succeeded refund
     *     transaction for the refunded amount the event carries ($event->amountMinor), falling back
     *     to the full captured total only when the provider supplies no amount AND no refund row
     *     exists yet (preserving the legacy full-refund-via-webhook behavior).
     *
     * Every recorded/settled amount is clamped to the order's remaining refundable balance, so the
     * cumulative succeeded refund can NEVER exceed the captured total (no double / over-refund).
     * Settling is idempotent: an amount-less confirmation of an already-settled refund is a no-op.
     *
     * @return array{type: string, order: Order}|null
     */
    private function applyRefund(Order $order, WebhookEvent $event, string $providerName): ?array
    {
        $reference = $event->providerReference;
        $totalMinor = (int) $order->getAttribute('total_minor');

        // Refunds already settled as succeeded — the ceiling any new/settled refund is clamped to.
        $alreadyRefundedMinor = (int) $order->transactions()
            ->where('type', TransactionType::Refund->value)
            ->where('status', TransactionStatus::Succeeded->value)
            ->sum('amount_minor');

        $remainingMinor = max(0, $totalMinor - $alreadyRefundedMinor);

        // A merchant-initiated refund awaiting confirmation leaves a PENDING refund transaction.
        // Prefer the row carrying this provider reference, else the latest pending row.
        $pendingTxn = $order->transactions()
            ->where('type', TransactionType::Refund->value)
            ->where('status', TransactionStatus::Pending->value)
            ->when($reference !== null, fn ($q) => $q->where('provider_reference', $reference))
            ->latest('id')
            ->first()
            ?? $order->transactions()
                ->where('type', TransactionType::Refund->value)
                ->where('status', TransactionStatus::Pending->value)
                ->latest('id')
                ->first();

        if ($pendingTxn !== null) {
            // Settle the refund we were waiting on. Clamp its amount to the remaining balance so a
            // settled refund can never carry the ledger past the captured total.
            $settled = min((int) $pendingTxn->getAttribute('amount_minor'), $remainingMinor);

            $pendingTxn->forceFill([
                'status' => TransactionStatus::Succeeded->value,
                'amount_minor' => $settled,
                'provider_reference' => $pendingTxn->getAttribute('provider_reference') ?? $reference,
            ])->save();
        } elseif ($event->amountMinor !== null) {
            // Provider-initiated refund carrying an explicit amount: record a NEW succeeded refund,
            // clamped to the remaining refundable balance.
            $amount = min((int) $event->amountMinor, $remainingMinor);

            if ($amount > 0) {
                PaymentTransaction::create([
                    'order_id' => $order->id,
                    'provider' => $providerName,
                    'provider_reference' => $reference,
                    'type' => TransactionType::Refund->value,
                    'status' => TransactionStatus::Succeeded->value,
                    'amount_minor' => $amount,
                    'currency' => $order->getAttribute('currency'),
                ]);
            }
        } elseif ($this->hasNoRefundTransaction($order)) {
            // Legacy: a provider-initiated FULL refund with no amount and no prior refund row.
            PaymentTransaction::create([
                'order_id' => $order->id,
                'provider' => $providerName,
                'provider_reference' => $reference,
                'type' => TransactionType::Refund->value,
                'status' => TransactionStatus::Succeeded->value,
                'amount_minor' => $remainingMinor,
                'currency' => $order->getAttribute('currency'),
            ]);
        }
        // else: an amount-less confirmation of an already-settled refund — ledger untouched (no-op).

        // Settle the Refund record (Refunds domain). Locked to serialize with a concurrent
        // synchronous refund; only a non-succeeded record is advanced, keeping it immutable once
        // final.
        $refund = Refund::where('order_id', $order->id)
            ->latest('id')
            ->lockForUpdate()
            ->first();

        if ($refund !== null && $refund->getAttribute('status') !== RefundStatus::Succeeded) {
            $refund->forceFill([
                'status' => RefundStatus::Succeeded->value,
                'provider_reference' => $refund->getAttribute('provider_reference') ?? $reference,
            ])->save();
        }

        // Full-vs-partial: only a CUMULATIVELY full refund flips the order to Refunded (and revokes
        // enrollments / issues a credit note). Basing this on the sum of succeeded refund
        // transactions also covers a provider-initiated refund that has no Refund row. The recorded
        // amounts are clamped above, so this sum can never exceed the captured total.
        $refundedMinor = (int) $order->transactions()
            ->where('type', TransactionType::Refund->value)
            ->where('status', TransactionStatus::Succeeded->value)
            ->sum('amount_minor');

        if ($refundedMinor < $totalMinor) {
            return null;
        }

        // Transition + emit only from a live paid state. Already-Refunded (or never-paid) orders
        // settle the ledger above but do not re-emit OrderRefunded.
        if (! in_array($order->getAttribute('status'), [OrderStatus::Paid, OrderStatus::Refunding], true)) {
            return null;
        }

        $order->forceFill(['status' => OrderStatus::Refunded->value, 'refunded_at' => now()])->save();

        return ['type' => 'refunded', 'order' => $order];
    }

    /** True when the order has no refund transaction of any status yet. */
    private function hasNoRefundTransaction(Order $order): bool
    {
        return ! $order->transactions()
            ->where('type', TransactionType::Refund->value)
            ->exists();
    }
}
