<?php

namespace App\Contexts\Commerce\Services;

use App\Contexts\Commerce\Models\CreditNote;
use App\Platform\Shared\Services\BaseService;
use Illuminate\Support\Facades\DB;

/**
 * Allocates human-readable, gap-tolerant credit-note numbers of the form CN-YYYY-000001. The
 * sequence resets each calendar year and is derived under a row lock from the highest existing
 * number for that year, so concurrent issuance never mints a duplicate. Mirrors
 * InvoiceNumberService; the 'CN' prefix distinguishes credit notes from invoices.
 */
class CreditNoteNumberService extends BaseService
{
    private const PREFIX = 'CN';

    /**
     * Return the next credit-note number for the given year (current year by default). Serialized
     * with a lockForUpdate scan so parallel callers each receive a distinct, sequential number.
     */
    public function next(?int $year = null): string
    {
        $year = $year ?? (int) now()->year;

        return DB::transaction(function () use ($year): string {
            $prefix = sprintf('%s-%04d-', self::PREFIX, $year);

            $last = CreditNote::query()
                ->where('number', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('number')
                ->value('number');

            $sequence = $last !== null
                ? ((int) substr((string) $last, strlen($prefix))) + 1
                : 1;

            return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
        });
    }
}
