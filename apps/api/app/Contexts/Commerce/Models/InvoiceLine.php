<?php

namespace App\Contexts\Commerce\Models;

use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable snapshot line of an invoice: the description, quantity, and unit price captured
 * from the order item at the moment the invoice is finalized, plus the VAT apportioned to this
 * line. Money is integer minor units only. Lines are a read-side snapshot — once written for a
 * finalized invoice they are never mutated.
 *
 * @property-read Invoice|null $invoice
 * @property int $id
 * @property int $invoice_id
 * @property string $description
 * @property int $quantity
 * @property int $unit_amount_minor
 * @property int $tax_minor
 * @property int $total_minor
 * @property string $public_id
 */
class InvoiceLine extends Model
{
    use HasPublicId;

    protected $fillable = [
        'invoice_id',
        'description',
        'quantity',
        'unit_amount_minor',
        'tax_minor',
        'total_minor',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_amount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function quantity(): int
    {
        return (int) $this->getAttribute('quantity');
    }

    public function unitAmountMinor(): int
    {
        return (int) $this->getAttribute('unit_amount_minor');
    }

    public function taxMinor(): int
    {
        return (int) $this->getAttribute('tax_minor');
    }

    public function totalMinor(): int
    {
        return (int) $this->getAttribute('total_minor');
    }
}
