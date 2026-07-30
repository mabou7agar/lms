<?php

namespace App\Contexts\Commerce\Models;

use App\Contexts\Commerce\Enums\BillingInterval;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subscription plan: a recurring, billable offer (monthly/quarterly/…) optionally backed by a
 * catalogue Product whose courses form the plan's entitlement. Prices are held per currency in the
 * related subscription_plan_prices rows (one is the default); money is never stored on the plan
 * itself. trial_days, when non-zero, grants a no-charge trial before the first renewal charges.
 *
 * @property-read Collection<int, SubscriptionPlanPrice> $prices
 * @property-read Product|null $product
 * @property string $public_id
 * @property int $id
 * @property int|null $product_id
 * @property string $name
 * @property BillingInterval $interval
 * @property int $trial_days
 * @property bool $is_active
 */
class SubscriptionPlan extends Model
{
    use HasPublicId;

    protected $fillable = [
        'product_id',
        'name',
        'interval',
        'trial_days',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'interval' => BillingInterval::class,
            'trial_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<SubscriptionPlanPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(SubscriptionPlanPrice::class, 'plan_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Typed interval accessor for PHPStan-clean reads. */
    public function intervalEnum(): BillingInterval
    {
        $interval = $this->getAttribute('interval');

        return $interval instanceof BillingInterval
            ? $interval
            : BillingInterval::from((string) $interval);
    }

    public function trialDays(): int
    {
        return max(0, (int) $this->getAttribute('trial_days'));
    }

    public function isActive(): bool
    {
        return (bool) $this->getAttribute('is_active');
    }

    /**
     * The price row for a currency, falling back to the plan's default price when the requested
     * currency is not published. Returns null only when the plan has no prices at all.
     */
    public function priceFor(?string $currency = null): ?SubscriptionPlanPrice
    {
        $prices = $this->prices;

        if ($currency !== null) {
            $match = $prices->first(fn (SubscriptionPlanPrice $price) => $price->getAttribute('currency') === $currency);

            if ($match instanceof SubscriptionPlanPrice) {
                return $match;
            }
        }

        $default = $prices->first(fn (SubscriptionPlanPrice $price) => $price->isDefault());

        return $default instanceof SubscriptionPlanPrice ? $default : $prices->first();
    }

    /** Minor-unit amount for a currency (0 when the plan has no matching or default price). */
    public function amountMinorFor(?string $currency = null): int
    {
        return $this->priceFor($currency)?->amountMinor() ?? 0;
    }
}
