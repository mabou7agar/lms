<?php

namespace App\Contexts\Commerce\Actions\Payment;

use App\Contexts\Commerce\Contracts\PaymentGateway;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\TransactionStatus;
use App\Contexts\Commerce\Enums\TransactionType;
use App\Contexts\Commerce\Exceptions\OrderNotPayableException;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\PaymentAttempt;
use App\Contexts\Commerce\Models\PaymentTransaction;
use App\Contexts\Commerce\Payments\Data\ChargeRequest;
use App\Contexts\Commerce\Payments\Data\ChargeResult;
use App\Platform\Shared\Actions\BaseAction;

/**
 * (Re)initiates payment for a pending/failed order via the gateway abstraction.
 *
 * Gateway I/O runs OUTSIDE any DB transaction: holding a transaction open across a network
 * round-trip risks a charge that succeeds while the commit later fails (customer charged, no
 * PaymentTransaction row). The charge is sent first; only the resulting order-status +
 * PaymentTransaction writes are wrapped in a short transaction. A deterministic idempotency key
 * (order public_id + next attempt ordinal) lets gateways that support it dedupe a duplicated or
 * concurrent dunning retry of the SAME attempt, so a card is never double-charged.
 */
class InitiatePaymentAction extends BaseAction
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function execute(Order $order): ChargeResult
    {
        if (! in_array($order->status, [OrderStatus::Pending, OrderStatus::Failed], true)) {
            throw new OrderNotPayableException;
        }

        $publicId = (string) $order->getAttribute('public_id');
        $attemptNo = 1 + (int) PaymentAttempt::query()
            ->where('order_id', $order->id)
            ->max('attempt_no');

        // Charge FIRST, outside any transaction. The idempotency key covers duplicate/concurrent
        // retries of this attempt so the provider does not charge the card twice.
        $charge = $this->gateway->charge(new ChargeRequest(
            reference: $order->public_id,
            amountMinor: $order->total_minor,
            currency: $order->currency,
            description: 'HElbaron order '.$publicId,
            idempotencyKey: $publicId.':r'.$attemptNo,
        ));

        // Persist the outcome in a short transaction — no network call is held open inside it.
        $this->transaction(function () use ($order, $charge): void {
            $order->forceFill(['status' => OrderStatus::Pending->value])->save();

            PaymentTransaction::create([
                'order_id' => $order->id,
                'provider' => (string) config('commerce.payment.provider'),
                'provider_reference' => $charge->providerReference,
                'type' => TransactionType::Charge->value,
                'status' => TransactionStatus::Pending->value,
                'amount_minor' => $order->total_minor,
                'currency' => $order->currency,
            ]);
        });

        return $charge;
    }
}
