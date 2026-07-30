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
     * Confirm an asynchronous refund: settle the refund ledger (the latest refund transaction and
     * the Refund record) as succeeded and move the order to Refunded — but only when the order is
     * still Paid or Refunding. A refund already finalized synchronously (RefundOrderAction) leaves
     * the order Refunded, so this guard prevents a second OrderRefunded (and a second enrollment
     * revocation). Settling the ledger is itself idempotent (skips rows already succeeded).
     *
     * @return array{type: string, order: Order}|null
     */
    private function applyRefund(Order $order, WebhookEvent $event, string $providerName): ?array
    {
        $reference = $event->providerReference;

        // Settle the refund payment-transaction ledger. Update the latest refund row when present;
        // otherwise record one (a provider-initiated refund may have no prior transaction) so the
        // order reconciles. Never create a second refund row for the same order.
        $refundTxn = $order->transactions()
            ->where('type', TransactionType::Refund->value)
            ->latest('id')
            ->first();

        if ($refundTxn !== null) {
            if ($refundTxn->getAttribute('status') !== TransactionStatus::Succeeded) {
                $refundTxn->forceFill([
                    'status' => TransactionStatus::Succeeded->value,
                    'provider_reference' => $refundTxn->getAttribute('provider_reference') ?? $reference,
                ])->save();
            }
        } else {
            PaymentTransaction::create([
                'order_id' => $order->id,
                'provider' => $providerName,
                'provider_reference' => $reference,
                'type' => TransactionType::Refund->value,
                'status' => TransactionStatus::Succeeded->value,
                'amount_minor' => $order->getAttribute('total_minor'),
                'currency' => $order->getAttribute('currency'),
            ]);
        }

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
        // enrollments / issues a credit note). A partial async refund settles the ledger above but
        // leaves the order Paid — mirroring RefundOrderAction phase 3. Basing this on the sum of
        // succeeded refund transactions (not Refund rows) also covers a provider-initiated refund
        // that has no prior Refund row (its settled transaction carries the amount).
        $refundedMinor = (int) $order->transactions()
            ->where('type', TransactionType::Refund->value)
            ->where('status', TransactionStatus::Succeeded->value)
            ->sum('amount_minor');

        if ($refundedMinor < (int) $order->getAttribute('total_minor')) {
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
}
