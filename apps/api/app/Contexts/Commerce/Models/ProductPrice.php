<?php

namespace App\Contexts\Commerce\Models;

use App\Contexts\Commerce\Database\Factories\ProductPriceFactory;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $currency
 * @property int $amount_minor
 * @property int|null $sale_amount_minor
 * @property bool $is_default
 * @property Carbon|null $sale_starts_at
 * @property Carbon|null $sale_ends_at
 */
class ProductPrice extends Model
{
    /** @use HasFactory<ProductPriceFactory> */
    use HasFactory;

    use HasPublicId;

    protected $fillable = [
        'product_id', 'currency', 'amount_minor', 'sale_amount_minor', 'sale_starts_at', 'sale_ends_at', 'is_default',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'sale_amount_minor' => 'integer',
            'sale_starts_at' => 'datetime',
            'sale_ends_at' => 'datetime',
            'is_default' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Effective (sale-aware) unit amount in minor units. */
    public function effectiveMinor(): int
    {
        if ($this->onSale()) {
            return (int) $this->sale_amount_minor;
        }

        return (int) $this->amount_minor;
    }

    public function onSale(): bool
    {
        if ($this->sale_amount_minor === null) {
            return false;
        }

        $now = now();
        $started = $this->sale_starts_at === null || $this->sale_starts_at->lte($now);
        $notEnded = $this->sale_ends_at === null || $this->sale_ends_at->gte($now);

        return $started && $notEnded;
    }

    protected static function newFactory(): ProductPriceFactory
    {
        return ProductPriceFactory::new();
    }
}
