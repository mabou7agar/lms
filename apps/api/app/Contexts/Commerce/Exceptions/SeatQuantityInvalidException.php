<?php

namespace App\Contexts\Commerce\Exceptions;

/**
 * The seat count a buyer chose is not one this product is sold in.
 *
 * The details carry the bounds that were violated so the buy box can say what IS allowed rather
 * than only that the request failed — a company told "invalid" has to guess; a company told
 * "between 5 and 200, in steps of 5" fixes it in one attempt.
 */
class SeatQuantityInvalidException extends CommerceException
{
    protected string $errorCode = 'COMMERCE_SEAT_QUANTITY_INVALID';

    protected int $status = 422;

    public function __construct(string $message = 'That seat count is not available for this product.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
