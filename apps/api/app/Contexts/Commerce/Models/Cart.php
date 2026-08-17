<?php

namespace App\Contexts\Commerce\Models;

use App\Contexts\Commerce\Enums\BuyerType;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property BuyerType|null $buyer_type
 * @property int|null $organization_id
 */
/**
 * @property int $user_id
 * @property string $currency
 * @property int|null $organization_id
 */
class Cart extends Model
{
    use HasPublicId;

    protected $fillable = ['user_id', 'currency', 'coupon_id', 'buyer_type', 'organization_id'];

    protected function casts(): array
    {
        return ['buyer_type' => BuyerType::class];
    }

    /**
     * The buyer this cart belongs to. A cart created before buyer ownership existed has no value in
     * memory, and an individual purchase is the safe reading — it is the more restricted of the two.
     */
    public function buyerType(): BuyerType
    {
        return $this->buyer_type ?? BuyerType::Individual;
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
