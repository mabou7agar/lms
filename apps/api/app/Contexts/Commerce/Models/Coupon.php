<?php

namespace App\Contexts\Commerce\Models;

use App\Contexts\Commerce\Database\Factories\CouponFactory;
use App\Contexts\Commerce\Enums\CouponScope;
use App\Contexts\Commerce\Enums\CouponType;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property bool $is_active
 */
class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory;

    use HasPublicId;

    protected $fillable = [
        'code', 'type', 'value', 'scope', 'currency', 'max_redemptions', 'redeemed_count',
        'starts_at', 'ends_at', 'is_active', 'per_user_limit', 'first_order_only',
        'min_subtotal_minor', 'stackable',
    ];

    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'scope' => CouponScope::class,
            'value' => 'integer',
            'max_redemptions' => 'integer',
            'redeemed_count' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'per_user_limit' => 'integer',
            'first_order_only' => 'boolean',
            'min_subtotal_minor' => 'integer',
            'stackable' => 'boolean',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'coupon_products');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    public function isWithinWindow(): bool
    {
        $now = now();

        return ($this->starts_at === null || $this->starts_at->lte($now))
            && ($this->ends_at === null || $this->ends_at->gte($now));
    }

    public function isExhausted(): bool
    {
        return $this->max_redemptions !== null && $this->redeemed_count >= $this->max_redemptions;
    }

    /** Per-user redemption cap (null = unlimited). Typed read for PHPStan-clean callers. */
    public function perUserLimit(): ?int
    {
        $value = $this->getAttribute('per_user_limit');

        return $value === null ? null : (int) $value;
    }

    /** Whether the coupon is restricted to a user's first (never-yet-paid) order. */
    public function isFirstOrderOnly(): bool
    {
        return (bool) $this->getAttribute('first_order_only');
    }

    /** Minimum eligible subtotal in minor units (null = no minimum). */
    public function minSubtotalMinor(): ?int
    {
        $value = $this->getAttribute('min_subtotal_minor');

        return $value === null ? null : (int) $value;
    }

    /** Whether the coupon may be combined with other coupons. */
    public function isStackable(): bool
    {
        return (bool) $this->getAttribute('stackable');
    }

    protected static function newFactory(): CouponFactory
    {
        return CouponFactory::new();
    }
}
