<?php

namespace App\Contexts\Commerce\Models;

use App\Contexts\Commerce\Enums\RefundReason;
use App\Contexts\Commerce\Enums\RefundStatus;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One partial-or-full refund issued against an order. A refund is created pending, then settled
 * to succeeded/failed by the gateway result (RefundOrderAction) or the refund.succeeded webhook
 * (ProcessWebhookAction). The remaining refundable of an order is its paid total minus the sum of
 * its non-failed (pending + succeeded) refunds, so an in-flight refund reserves capacity and
 * cannot be double-spent.
 *
 * Financial immutability: once the status isFinal() (succeeded or failed) the record is frozen —
 * any further attribute change throws. Settlement itself uses forceFill(...)->save() so the single
 * transition into a final state is still permitted; this guard only blocks mutating an
 * already-final record. Money is integer minor units only.
 *
 * @property-read Order|null $order
 * @property-read PaymentTransaction|null $transaction
 * @property string $public_id
 * @property int $id
 * @property int $order_id
 * @property int|null $payment_transaction_id
 * @property int $amount_minor
 * @property string $currency
 * @property RefundStatus $status
 * @property RefundReason|null $reason
 * @property string|null $provider_reference
 * @property Carbon|null $processed_at
 */
class Refund extends Model
{
    use HasPublicId;

    protected $fillable = [
        'order_id',
        'payment_transaction_id',
        'amount_minor',
        'currency',
        'status',
        'reason',
        'provider_reference',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'reason' => RefundReason::class,
            'amount_minor' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Enforce financial immutability: a refund frozen in a final state (succeeded/failed) rejects
     * any further mutation. The transition INTO a final state is still allowed because the record
     * is not yet final at the moment its status is being written.
     */
    protected static function booted(): void
    {
        static::updating(function (self $refund): void {
            $original = $refund->getOriginal('status');

            $status = $original instanceof RefundStatus
                ? $original
                : ($original !== null ? RefundStatus::from((string) $original) : null);

            if ($status !== null && $status->isFinal()) {
                throw new RuntimeException('A finalized refund is immutable and cannot be modified.');
            }
        });
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<PaymentTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }

    /** Typed status accessor for PHPStan-clean reads. */
    public function statusEnum(): RefundStatus
    {
        $status = $this->getAttribute('status');

        return $status instanceof RefundStatus
            ? $status
            : RefundStatus::from((string) $status);
    }

    /** Typed reason accessor for PHPStan-clean reads (null when unspecified). */
    public function reasonEnum(): ?RefundReason
    {
        $reason = $this->getAttribute('reason');

        if ($reason === null) {
            return null;
        }

        return $reason instanceof RefundReason
            ? $reason
            : RefundReason::from((string) $reason);
    }

    public function amountMinor(): int
    {
        return (int) $this->getAttribute('amount_minor');
    }

    public function isFinal(): bool
    {
        return $this->statusEnum()->isFinal();
    }
}
