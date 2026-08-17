<?php

namespace App\Contexts\Commerce\Exceptions;

/**
 * This product is not sold self-service; a company has to talk to sales.
 *
 * Since the seat-purchasing wave this is a deliberate admin choice (`seat_mode = quote_only`),
 * not a missing feature. `buyer_selects` is now genuinely sellable: the buyer picks a count inside
 * the admin's bounds and the price follows the configured basis.
 */
class SeatQuantityUnavailableException extends CommerceException
{
    protected string $errorCode = 'COMMERCE_SEAT_QUANTITY_UNAVAILABLE';

    protected int $status = 422;

    public function __construct(string $message = 'This product is not sold online. Request a company quote for it.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
