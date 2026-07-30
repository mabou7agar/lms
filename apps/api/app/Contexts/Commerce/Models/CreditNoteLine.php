<?php

namespace App\Contexts\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a credit note: the description plus the net amount and VAT being credited back to
 * the customer, snapshotted (negated) from the corresponding invoice line at the moment the credit
 * note is issued. Money is integer minor units; stored amounts are positive magnitudes and the
 * parent credit note carries the negative sign against the customer ledger. Once its credit note is
 * issued these lines are never mutated.
 *
 * @property-read CreditNote|null $creditNote
 * @property int $id
 * @property int $credit_note_id
 * @property string $description
 * @property int $amount_minor
 * @property int $tax_minor
 */
class CreditNoteLine extends Model
{
    protected $fillable = [
        'credit_note_id',
        'description',
        'amount_minor',
        'tax_minor',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'tax_minor' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CreditNote, $this>
     */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function amountMinor(): int
    {
        return (int) $this->getAttribute('amount_minor');
    }

    public function taxMinor(): int
    {
        return (int) $this->getAttribute('tax_minor');
    }
}
