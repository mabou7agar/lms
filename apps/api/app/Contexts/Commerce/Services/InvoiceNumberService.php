<?php

namespace App\Contexts\Commerce\Services;

use App\Contexts\Commerce\Models\Invoice;
use App\Platform\Shared\Services\BaseService;
use Illuminate\Support\Facades\DB;

/**
 * Allocates a unique, human-readable invoice number, e.g. INV-2025-000123.
 *
 * Serialized with a lockForUpdate scan of the highest existing number for the year (like
 * CreditNoteNumberService), NOT a naive count()+1. The old count-based approach both reused gaps
 * (a deleted invoice's number reappeared) and, run inside two concurrent checkouts, read the same
 * count and minted the same number — the second Invoice::create then hit the `number` UNIQUE
 * constraint and rolled the whole checkout back.
 */
class InvoiceNumberService extends BaseService
{
    public function next(): string
    {
        $prefix = (string) config('commerce.invoice.prefix', 'INV');
        $year = (int) now()->format('Y');

        return DB::transaction(function () use ($prefix, $year): string {
            $like = sprintf('%s-%04d-', $prefix, $year);

            $last = Invoice::query()
                ->where('number', 'like', $like.'%')
                ->lockForUpdate()
                ->orderByDesc('number')
                ->value('number');

            $sequence = $last !== null
                ? ((int) substr((string) $last, strlen($like))) + 1
                : 1;

            return sprintf('%s-%04d-%06d', $prefix, $year, $sequence);
        });
    }
}
