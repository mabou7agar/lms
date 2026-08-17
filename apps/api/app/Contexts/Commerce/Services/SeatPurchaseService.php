<?php

namespace App\Contexts\Commerce\Services;

use App\Contexts\Commerce\Enums\BuyerType;
use App\Contexts\Commerce\Enums\SeatMode;
use App\Contexts\Commerce\Exceptions\SeatQuantityInvalidException;
use App\Contexts\Commerce\Exceptions\SeatQuantityUnavailableException;
use App\Contexts\Commerce\Models\Product;
use App\Platform\Shared\Services\BaseService;

/**
 * The one place that decides how many seats a purchase carries and what that costs.
 *
 * Cart, checkout and entitlement provisioning all ask this service rather than each applying the
 * rule themselves, because the three answers have to agree: a buyer who is shown a price for 25
 * seats must be charged for 25 seats and must receive 25 seats. Splitting the rule across those
 * three places is how a seat pool ends up a different size from the invoice.
 */
class SeatPurchaseService extends BaseService
{
    /**
     * The seat count to record for a line, or null when seats are not a concept for it.
     *
     * A quote-only product is refused outright. A buyer-selected product validates the requested
     * count against the admin's bounds — and refuses a count nobody supplied rather than silently
     * defaulting, because a default is a number the buyer never agreed to pay for.
     */
    public function resolveQuantity(Product $product, BuyerType $buyerType, ?int $requested): int
    {
        $mode = $product->seatMode();

        if ($mode->isQuoteOnly()) {
            throw new SeatQuantityUnavailableException;
        }

        if (! $mode->buyerChoosesSeats()) {
            // Fixed, unlimited and individual-only products carry no chosen quantity. A count sent
            // anyway is refused rather than ignored: silently dropping it would show the buyer one
            // number and charge them for another.
            if ($requested !== null && $requested !== 1) {
                throw new SeatQuantityInvalidException(
                    'This product is not sold by the seat.',
                    ['seat_mode' => $mode->value],
                );
            }

            return 1;
        }

        // Seats only mean something for a company. An individual reaching this product is refused
        // by the audience rule first, but a company-and-individual product can reach here.
        if (! $buyerType->isCompany()) {
            throw new SeatQuantityInvalidException(
                'Seats are bought by a company. Switch to a company purchase to choose a seat count.',
                ['buyer_type' => $buyerType->value],
            );
        }

        $bounds = $product->seatSelection();

        if ($bounds === null) {
            throw new SeatQuantityUnavailableException;
        }

        if ($requested === null) {
            throw new SeatQuantityInvalidException('Choose how many seats to buy.', $bounds);
        }

        if ($requested < $bounds['min'] || ($bounds['max'] !== null && $requested > $bounds['max'])) {
            throw new SeatQuantityInvalidException(
                $bounds['max'] === null
                    ? 'Choose at least '.$bounds['min'].' seat(s).'
                    : 'Choose between '.$bounds['min'].' and '.$bounds['max'].' seats.',
                $bounds,
            );
        }

        if (($requested - $bounds['min']) % $bounds['increment'] !== 0) {
            throw new SeatQuantityInvalidException(
                'Seats are sold in steps of '.$bounds['increment'].', starting at '.$bounds['min'].'.',
                $bounds,
            );
        }

        return $requested;
    }

    /** What a line of this product costs at the given unit price and seat count. */
    public function lineAmountMinor(Product $product, int $unitAmountMinor, int $quantity): int
    {
        return $product->lineAmountMinor($unitAmountMinor, $quantity);
    }

    /**
     * How many seats a company entitlement provisioned from this line should carry.
     *
     * Null means unlimited. A buyer-selected purchase carries exactly what was bought; a fixed
     * package carries the admin's count. Reading the ORDER rather than the product matters: the
     * product can be edited between the sale and fulfilment, and the company bought what it bought.
     */
    public function entitlementSeats(Product $product, int $quantity): ?int
    {
        $mode = $product->seatMode();

        if ($mode === SeatMode::Unlimited) {
            return null;
        }

        if ($mode->buyerChoosesSeats()) {
            return max(1, $quantity);
        }

        return max(1, (int) ($product->default_seat_count ?? 1));
    }
}
