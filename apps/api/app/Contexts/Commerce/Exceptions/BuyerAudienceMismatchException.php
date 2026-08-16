<?php

namespace App\Contexts\Commerce\Exceptions;

/**
 * Raised when a buyer tries to purchase a product that is not sold to them — an individual reaching
 * for a company-only licence, or a company buying something sold only to individuals.
 */
class BuyerAudienceMismatchException extends CommerceException
{
    protected string $errorCode = 'COMMERCE_BUYER_AUDIENCE_MISMATCH';

    protected int $status = 422;

    public function __construct(string $message = 'This product is not sold to this type of buyer.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
