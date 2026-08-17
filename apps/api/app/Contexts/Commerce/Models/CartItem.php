<?php

namespace App\Contexts\Commerce\Models;

use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $cart_id
 * @property int $product_id
 * @property int $unit_amount_minor
 * @property int|null $quantity
 * @property-read Product|null $product
 */
class CartItem extends Model
{
    use HasPublicId;

    protected $fillable = ['cart_id', 'product_id', 'unit_amount_minor', 'quantity'];

    protected function casts(): array
    {
        return ['unit_amount_minor' => 'integer', 'quantity' => 'integer'];
    }

    /** Seats on this line. A row saved before the seat-purchasing wave carries none, meaning one. */
    public function quantityOrOne(): int
    {
        return max(1, (int) ($this->getAttribute('quantity') ?? 1));
    }

    /**
     * The count to re-validate against the product's current bounds, or null when this product does
     * not sell by the seat — so a re-check cannot mistake an implicit 1 for a chosen count.
     */
    public function seatSelection(): ?int
    {
        $product = $this->relationLoaded('product') ? $this->getRelation('product') : $this->product;

        return $product instanceof Product && $product->seatMode()->buyerChoosesSeats()
            ? $this->quantityOrOne()
            : null;
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
