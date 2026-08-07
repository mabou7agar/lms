<?php

namespace App\Contexts\Commerce\Models;

use App\Contexts\Commerce\Database\Factories\OrderFactory;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\RefundStatus;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use HasPublicId;
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'status', 'currency', 'subtotal_minor', 'discount_minor', 'tax_minor', 'total_minor',
        'coupon_id', 'placed_at', 'paid_at', 'fulfilled_at', 'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'placed_at' => 'datetime',
            'paid_at' => 'datetime',
            'fulfilled_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * The purchasing user. Bound to the configured auth model so no cross-context Eloquent class is
     * imported here (mirrors Subscription::user()).
     *
     * @return BelongsTo<Model, $this>
     */
    public function user(): BelongsTo
    {
        /** @var class-string<Model> $model */
        $model = (string) config('auth.providers.users.model', 'App\\Models\\User');

        return $this->belongsTo($model, 'user_id');
    }

    /**
     * Append-only trail of payment attempts (checkout + dunning) against this order, oldest first by
     * attempt ordinal. Read-only: rows are written by PaymentRecoveryService.
     *
     * @return HasMany<PaymentAttempt, $this>
     */
    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class)->orderBy('attempt_no');
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class);
    }

    public function grants(): HasMany
    {
        return $this->hasMany(OrderCourseGrant::class);
    }

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function isPaid(): bool
    {
        return $this->status === OrderStatus::Paid || $this->paid_at !== null;
    }

    /** Sum of this order's succeeded refunds, in integer minor units. */
    public function refundedTotalMinor(): int
    {
        return (int) $this->refunds()
            ->where('status', RefundStatus::Succeeded->value)
            ->sum('amount_minor');
    }

    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }
}
