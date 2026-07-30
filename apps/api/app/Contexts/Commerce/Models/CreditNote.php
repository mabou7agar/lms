<?php

namespace App\Contexts\Commerce\Models;

use App\Contexts\Commerce\Enums\CreditNoteStatus;
use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * A credit note issued against an order when money is returned to the customer — the accounting
 * counter-document to an invoice. Created directly in the issued state by IssueCreditNoteOnRefund
 * for the refunded amount, mirroring the invoice's lines. Money is integer minor units; stored
 * amounts are positive magnitudes and the document type (credit note) carries the negative sign
 * against the customer ledger.
 *
 * Financial immutability: once the credit note is issued (or void) the record is frozen — any
 * further attribute change throws. A draft credit note may still be mutated. Since the issuing
 * flow creates the record already in the issued state, this guard only blocks mutating an
 * already-finalized note.
 *
 * @property-read Order|null $order
 * @property-read Refund|null $refund
 * @property string $public_id
 * @property int $id
 * @property int $order_id
 * @property int|null $refund_id
 * @property string $number
 * @property CreditNoteStatus $status
 * @property string $currency
 * @property int $total_minor
 * @property Carbon|null $issued_at
 */
class CreditNote extends Model
{
    use HasPublicId;

    protected $fillable = [
        'order_id',
        'refund_id',
        'number',
        'status',
        'currency',
        'total_minor',
        'issued_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => CreditNoteStatus::class,
            'total_minor' => 'integer',
            'issued_at' => 'datetime',
        ];
    }

    /**
     * Enforce financial immutability: a credit note frozen in a final state (issued/void) rejects
     * any further mutation. A draft note is still mutable, and the transition into a final state on
     * creation is allowed because a fresh record has no original final status.
     */
    protected static function booted(): void
    {
        static::updating(function (self $creditNote): void {
            $original = $creditNote->getOriginal('status');

            $status = $original instanceof CreditNoteStatus
                ? $original
                : ($original !== null ? CreditNoteStatus::from((string) $original) : null);

            if ($status === CreditNoteStatus::Issued || $status === CreditNoteStatus::Void) {
                throw new RuntimeException('An issued or void credit note is immutable and cannot be modified.');
            }
        });
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<Refund, $this>
     */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    /**
     * @return HasMany<CreditNoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CreditNoteLine::class);
    }

    /** Typed status accessor for PHPStan-clean reads. */
    public function statusEnum(): CreditNoteStatus
    {
        $status = $this->getAttribute('status');

        return $status instanceof CreditNoteStatus
            ? $status
            : CreditNoteStatus::from((string) $status);
    }

    public function totalMinor(): int
    {
        return (int) $this->getAttribute('total_minor');
    }
}
