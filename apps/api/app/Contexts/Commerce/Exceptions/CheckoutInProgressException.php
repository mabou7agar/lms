<?php

namespace App\Contexts\Commerce\Exceptions;

/**
 * Raised when a second checkout is attempted for a user while one is already in flight.
 * Guards against a duplicate submit (double-click / concurrent request) producing a second
 * order and a second gateway charge from the same cart.
 */
class CheckoutInProgressException extends CommerceException
{
    protected string $errorCode = 'COMMERCE_CHECKOUT_IN_PROGRESS';

    protected int $status = 409;

    public function __construct(
        string $message = 'A checkout is already being processed for your cart. Please wait a moment and try again.',
        array $details = [],
    ) {
        parent::__construct($message, $details);
    }
}
