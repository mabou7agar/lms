<?php

namespace App\Contexts\Commerce\Http\Resources;

use App\Contexts\Commerce\Models\CreditNote;
use App\Platform\Shared\Resources\BaseResource;
use Illuminate\Http\Request;

/**
 * Admin read model for a credit note: the number, status, currency, credited total, the issue
 * timestamp, the owning order's public id, and the immutable line snapshot. All money fields are
 * integer minor units (positive magnitudes; the credit note document represents the negation).
 * Read-only shaping — no business logic, no persistence.
 *
 * @property CreditNote $resource
 */
class CreditNoteResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $creditNote = $this->resource;

        return [
            'id' => $creditNote->public_id,
            'number' => $creditNote->number,
            'status' => $creditNote->statusEnum()->value,
            'currency' => (string) $creditNote->getAttribute('currency'),
            'total_minor' => (int) $creditNote->getAttribute('total_minor'),
            'issued_at' => $creditNote->issued_at?->toIso8601String(),
            'order_id' => $this->whenLoaded('order', fn () => $creditNote->order?->getAttribute('public_id')),

            'lines' => $this->whenLoaded('lines', fn () => $creditNote->lines->map(fn ($line) => [
                'id' => $line->getKey(),
                'description' => $line->description,
                'amount_minor' => (int) $line->amount_minor,
                'tax_minor' => (int) $line->tax_minor,
            ])->values()),
        ];
    }
}
