<?php

namespace App\Contexts\Commerce\Payments\Recovery;

use App\Contexts\Commerce\Actions\Payment\InitiatePaymentAction;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\PaymentAttemptStatus;
use App\Contexts\Commerce\Models\Order;
use App\Contexts\Commerce\Models\PaymentAttempt;
use App\Contexts\Commerce\Payments\Data\ChargeResult;
use App\Platform\Shared\Services\BaseService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Failed-payment recovery (dunning). Two jobs:
 *
 *  - record(): append one immutable PaymentAttempt row per charge initiation, numbering the tries
 *    per order. This is the audit trail the retry logic and any reporting read from.
 *  - retryFailed(): re-initiate payment for a failed order by reusing InitiatePaymentAction, but
 *    only inside the dunning window, under the max-attempts ceiling, and after the exponential
 *    backoff since the previous try has elapsed. When the window closes or the ceiling is hit, the
 *    order's open attempts are marked abandoned so it stops being retried.
 *
 * Gateway I/O happens inside InitiatePaymentAction (never here), so this service performs no
 * network calls of its own. Money stays integer minor units throughout.
 */
class PaymentRecoveryService extends BaseService
{
    /** Base backoff between retries; doubles per prior attempt up to the cap. */
    private const BACKOFF_BASE_MINUTES = 30;

    private const BACKOFF_CAP_MINUTES = 1440;

    public function __construct(
        private readonly InitiatePaymentAction $initiatePayment,
    ) {}

    /**
     * Record a single payment attempt for an order. attempt_no is the next ordinal for that order.
     */
    public function record(Order $order, ChargeResult $result, ?string $errorCode = null): PaymentAttempt
    {
        return DB::transaction(function () use ($order, $result, $errorCode): PaymentAttempt {
            // Serialize attempt-number allocation with a locked read of the highest existing
            // attempt_no. NB: Postgres rejects `FOR UPDATE` combined with an aggregate
            // (max()), so this locks the top row via an ordered value() read instead.
            $previous = (int) (PaymentAttempt::query()
                ->where('order_id', $order->id)
                ->orderByDesc('attempt_no')
                ->lockForUpdate()
                ->value('attempt_no') ?? 0);

            return PaymentAttempt::create([
                'order_id' => $order->id,
                'provider' => (string) config('commerce.payment.provider'),
                'provider_reference' => $result->providerReference !== '' ? $result->providerReference : null,
                'status' => $this->mapStatus($result->status)->value,
                'amount_minor' => (int) $order->getAttribute('total_minor'),
                'currency' => (string) $order->getAttribute('currency'),
                'error_code' => $errorCode,
                'attempt_no' => $previous + 1,
            ]);
        });
    }

    /**
     * Re-initiate payment for a failed order, honouring the dunning window, max-attempts ceiling,
     * and per-attempt backoff. Returns the new attempt, or null when the order is skipped or retired.
     */
    public function retryFailed(Order $order): ?PaymentAttempt
    {
        if (! $this->isFailed($order)) {
            return null;
        }

        $maxAttempts = max(1, (int) config('commerce.dunning.max_attempts', 4));
        $windowHours = max(1, (int) config('commerce.dunning.window_hours', 72));

        if ($this->outsideWindow($order, $windowHours)) {
            $this->abandon($order);

            return null;
        }

        $attempts = PaymentAttempt::query()
            ->where('order_id', $order->id)
            ->orderByDesc('attempt_no')
            ->get();

        if ($attempts->count() >= $maxAttempts) {
            $this->abandon($order);

            return null;
        }

        $last = $attempts->first();
        if ($last !== null && ! $this->backoffElapsed($last)) {
            return null;
        }

        try {
            $result = $this->initiatePayment->execute($order);
        } catch (Throwable $e) {
            return $this->record($order, new ChargeResult(
                providerReference: '',
                status: 'failed',
            ), $this->errorCode($e));
        }

        return $this->record($order, $result);
    }

    private function mapStatus(string $status): PaymentAttemptStatus
    {
        return match ($status) {
            'succeeded' => PaymentAttemptStatus::Succeeded,
            'pending' => PaymentAttemptStatus::Pending,
            default => PaymentAttemptStatus::Failed,
        };
    }

    private function isFailed(Order $order): bool
    {
        $status = $order->getAttribute('status');

        return ($status instanceof OrderStatus ? $status : OrderStatus::from((string) $status)) === OrderStatus::Failed;
    }

    private function outsideWindow(Order $order, int $windowHours): bool
    {
        $placedAt = $order->getAttribute('placed_at');

        if (! $placedAt instanceof Carbon) {
            return false;
        }

        return $placedAt->copy()->addHours($windowHours)->isPast();
    }

    private function backoffElapsed(PaymentAttempt $last): bool
    {
        $updatedAt = $last->getAttribute('updated_at');

        if (! $updatedAt instanceof Carbon) {
            return true;
        }

        $minutes = min(
            self::BACKOFF_BASE_MINUTES * (2 ** max(0, $last->attemptNo() - 1)),
            self::BACKOFF_CAP_MINUTES,
        );

        return $updatedAt->copy()->addMinutes($minutes)->isPast();
    }

    /** Retire an order's open attempts once it can no longer be recovered. */
    private function abandon(Order $order): void
    {
        PaymentAttempt::query()
            ->where('order_id', $order->id)
            ->where('status', PaymentAttemptStatus::Pending->value)
            ->update(['status' => PaymentAttemptStatus::Abandoned->value]);
    }

    private function errorCode(Throwable $e): string
    {
        $code = $e->getCode();

        return $code !== 0 && $code !== '' ? (string) $code : 'retry_failed';
    }
}
