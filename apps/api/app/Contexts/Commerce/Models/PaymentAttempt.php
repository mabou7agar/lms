<?php

namespace App\Contexts\Commerce\Models;

use App\Contexts\Commerce\Enums\PaymentAttemptStatus;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One record per payment initiation against an order — the audit trail behind failed-payment
 * recovery and dunning. attempt_no is the 1-based ordinal of the try; status tracks its outcome
 * (pending while the shopper is on the hosted page, then succeeded/failed, or abandoned once the
 * dunning window closes). Money is integer minor units only.
 *
 * @property-read Order|null $order
 * @property string $public_id
 * @property int $id
 * @property int $order_id
 * @property string $provider
 * @property string|null $provider_reference
 * @property PaymentAttemptStatus $status
 * @property int $amount_minor
 * @property string $currency
 * @property string|null $error_code
 * @property int $attempt_no
 */
class PaymentAttempt extends Model
{
    use HasPublicId;

    protected $fillable = [
        'order_id',
        'provider',
        'provider_reference',
        'status',
        'amount_minor',
        'currency',
        'error_code',
        'attempt_no',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentAttemptStatus::class,
            'amount_minor' => 'integer',
            'attempt_no' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Typed status accessor for PHPStan-clean reads. */
    public function statusEnum(): PaymentAttemptStatus
    {
        $status = $this->getAttribute('status');

        return $status instanceof PaymentAttemptStatus
            ? $status
            : PaymentAttemptStatus::from((string) $status);
    }

    public function attemptNo(): int
    {
        return (int) $this->getAttribute('attempt_no');
    }
}
