<?php

namespace App\Contexts\Commerce\Exceptions;

/**
 * A product priced per a buyer-chosen number of seats cannot be sold yet.
 *
 * `seat_mode = buyer_selects` promises the company picks how many seats it wants at checkout, and
 * nothing in the purchase flow captures that number: cart and order items carry no quantity, and no
 * price row says whether a chosen quantity multiplies the price or buys a pack at a flat rate. The
 * seat wave therefore fell back to the admin's default count, which quietly sold a different number
 * of seats than the buyer would have picked.
 *
 * Refusing is the correct behaviour until quantity and its pricing rule exist. The alternative —
 * guessing — either overcharges the company or gives away seats, and does it silently.
 */
class SeatQuantityUnavailableException extends CommerceException
{
    protected string $errorCode = 'COMMERCE_SEAT_QUANTITY_UNAVAILABLE';

    protected int $status = 422;

    public function __construct(string $message = 'Seat quantity selection is not available yet. Request a company quote for this product.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
