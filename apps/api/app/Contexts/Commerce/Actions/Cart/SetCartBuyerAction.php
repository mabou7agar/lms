<?php

namespace App\Contexts\Commerce\Actions\Cart;

use App\Contexts\Commerce\Enums\BuyerType;
use App\Contexts\Commerce\Exceptions\BuyerAudienceMismatchException;
use App\Contexts\Commerce\Exceptions\CompanyBuyerRequiredException;
use App\Contexts\Commerce\Models\Cart;
use App\Contexts\Commerce\Services\CartService;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Enterprise\Contracts\OrganizationLookupPort;
use App\Platform\Shared\Enterprise\Contracts\OrgManagerCheckPort;

/**
 * Switches a cart between an individual and a company purchase.
 *
 * The organization is resolved from the caller's own active membership rather than accepted from the
 * request, so a buyer can only ever purchase in the name of a company they actually manage. The
 * switch is refused when the cart already holds something the new buyer may not buy, which keeps a
 * cart from silently becoming unpurchasable.
 */
class SetCartBuyerAction extends BaseAction
{
    public function __construct(
        private readonly CartService $carts,
        private readonly OrganizationLookupPort $organizations,
        private readonly OrgManagerCheckPort $managers,
    ) {}

    public function executeByUserId(int $userId, BuyerType $buyerType): Cart
    {
        $cart = $this->carts->currentByUserId($userId);

        if (! $this->carts->isCompatibleWithBuyerType($cart, $buyerType)) {
            throw new BuyerAudienceMismatchException(
                $buyerType->isCompany()
                    ? 'Your cart contains something sold to individuals only. Remove it to buy as a company.'
                    : 'Your cart contains something sold to companies only. Remove it to buy as an individual.',
            );
        }

        $organizationId = null;

        if ($buyerType->isCompany()) {
            $organizationId = $this->organizations->managedOrganizationIdFor($userId);

            // Someone with no company (or only a plain membership) cannot buy on a company's behalf;
            // the UI sends them to company registration instead.
            if ($organizationId === null || ! $this->managers->managesAnyOrganization($userId)) {
                throw new CompanyBuyerRequiredException(
                    'Register a company account before making a company purchase.',
                );
            }
        }

        $cart->forceFill([
            'buyer_type' => $buyerType->value,
            'organization_id' => $organizationId,
        ])->save();

        return $cart->refresh();
    }
}
