<?php

namespace App\Contexts\Commerce\Models;

use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property int $product_id
 * @property string $title
 * @property int $unit_amount_minor
 * @property int|null $quantity
 * @property-read Product|null $product
 */
class OrderItem extends Model
{
    use HasPublicId;

    protected $fillable = ['order_id', 'product_id', 'title', 'unit_amount_minor', 'quantity'];

    protected function casts(): array
    {
        return ['unit_amount_minor' => 'integer', 'quantity' => 'integer'];
    }

    /** Seats sold on this line. A row from before the seat-purchasing wave carries none, meaning one. */
    public function quantityOrOne(): int
    {
        return max(1, (int) ($this->getAttribute('quantity') ?? 1));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
