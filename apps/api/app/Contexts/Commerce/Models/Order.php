<?php

namespace App\Contexts\Commerce\Models;

use App\Contexts\Commerce\Database\Factories\OrderFactory;
use App\Contexts\Commerce\Enums\BuyerType;
use App\Contexts\Commerce\Enums\OrderStatus;
use App\Contexts\Commerce\Enums\RefundStatus;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Buyer ownership fields are nullable: an order placed before buyer ownership existed carries no
 * value, and a model that has not been persisted has none in memory either.
 *
 * @property int $user_id
 * @property string $currency
 * @property int $total_minor
 * @property BuyerType|null $buyer_type
 * @property int|null $organization_id
 * @property string|null $company_name
 * @property string|null $billing_name
 * @property string|null $billing_email
 * @property string|null $billing_phone
 * @property string|null $billing_country
 * @property string|null $billing_tax_id
 * @property string|null $billing_address
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use HasPublicId;
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'status', 'currency', 'subtotal_minor', 'discount_minor', 'tax_minor', 'total_minor',
        'coupon_id', 'placed_at', 'paid_at', 'fulfilled_at', 'refunded_at',
        // Buyer ownership + the billing identity the invoice was issued to, snapshotted at purchase.
        'buyer_type', 'organization_id', 'company_name',
        'billing_name', 'billing_email', 'billing_phone', 'billing_country', 'billing_tax_id', 'billing_address',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'buyer_type' => BuyerType::class,
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
