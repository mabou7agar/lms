<?php

namespace App\Contexts\Commerce\Models;

use App\Platform\Shared\Traits\HasPublicId;
use Illuminate\Database\Eloquent\Model;

/**
 * The fiscal e-invoicing record for an invoice: which authority it was submitted to, the document
 * hash the authority anchored on, the clearance/reporting outcome, and the canonical payload sent.
 * `invoice_id` is an opaque reference (no cross-domain FK) to the Commerce invoice it represents.
 */
class EInvoiceDocument extends Model
{
    use HasPublicId;

    protected $fillable = [
        'invoice_id', 'provider', 'status', 'provider_reference', 'hash', 'payload', 'cleared_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'cleared_at' => 'datetime',
        ];
    }
}
