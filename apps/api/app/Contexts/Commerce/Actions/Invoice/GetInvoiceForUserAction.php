<?php

namespace App\Contexts\Commerce\Actions\Invoice;

use App\Contexts\Commerce\Models\Invoice;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Read-side use case: fetch a single invoice that belongs to the given user, eager-loading its
 * line snapshot for the billing portal detail view.
 *
 * Ownership is enforced in the query (invoice -> order -> user_id) — an invoice that is not the
 * user's simply does not resolve, so a caller can never read another user's invoice by guessing a
 * public id. Lookup is by public_id; internal auto-increment ids are never exposed.
 */
class GetInvoiceForUserAction extends BaseAction
{
    public function execute(int $userId, string $publicId): Invoice
    {
        return Invoice::query()
            ->where('public_id', $publicId)
            ->whereHas('order', fn ($query) => $query->where('user_id', $userId))
            ->with('lines')
            ->firstOrFail();
    }
}
