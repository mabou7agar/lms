<?php

namespace App\Contexts\Commerce\Actions\Order;

use App\Contexts\Commerce\Models\Order;
use App\Platform\Shared\Actions\BaseAction;

/**
 * Read-side use case: fetch a single order that belongs to the given user, eager-loading the
 * detail relations the order-detail endpoint renders (items, invoice, transactions).
 *
 * Ownership is enforced in the query — an order that is not the user's simply does not resolve,
 * so a caller can never read another user's order by guessing a public id. Lookup is by the
 * public_id (the external identifier); internal auto-increment ids are never exposed.
 */
class GetOrderForUserAction extends BaseAction
{
    public function execute(int $userId, string $publicId): Order
    {
        return Order::query()
            ->where('user_id', $userId)
            ->where('public_id', $publicId)
            ->with(['items', 'invoice', 'transactions'])
            ->firstOrFail();
    }
}
