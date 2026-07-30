<?php

namespace App\Contexts\Commerce\Actions\Invoice;

use App\Contexts\Commerce\Models\Invoice;
use App\Platform\Shared\Actions\BaseAction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read-side use case for the learner billing portal: list the invoices that belong to the given
 * user, newest first, eager-loading the line snapshot the portal renders. Ownership is enforced in
 * the query (invoice -> order -> user_id), so a user can never page another user's invoices.
 */
class ListInvoicesForUserAction extends BaseAction
{
    /**
     * @return LengthAwarePaginator<int, Invoice>
     */
    public function execute(int $userId, int $perPage = 15): LengthAwarePaginator
    {
        return Invoice::query()
            ->whereHas('order', fn ($query) => $query->where('user_id', $userId))
            ->with('lines')
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}
