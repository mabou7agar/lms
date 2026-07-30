<?php

namespace App\Contexts\Commerce\Actions\CreditNote;

use App\Contexts\Commerce\Models\CreditNote;
use App\Platform\Shared\Actions\BaseAction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Admin read-side use case: page the credit notes ledger, newest first, eager-loading the owning
 * order (for its public id) and the line snapshot the admin view renders. Authorization is enforced
 * by the route's permission middleware; this action performs no writes.
 */
class ListCreditNotesAction extends BaseAction
{
    /**
     * @return LengthAwarePaginator<int, CreditNote>
     */
    public function execute(int $perPage = 15): LengthAwarePaginator
    {
        return CreditNote::query()
            ->with(['order', 'lines'])
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
