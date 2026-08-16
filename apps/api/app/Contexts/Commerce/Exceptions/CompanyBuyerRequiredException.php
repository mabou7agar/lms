<?php

namespace App\Contexts\Commerce\Exceptions;

/**
 * Raised when a company purchase reaches checkout without an organization to own it. The order would
 * have no one to invoice and no one to receive the seats, so it must not be created.
 */
class CompanyBuyerRequiredException extends CommerceException
{
    protected string $errorCode = 'COMMERCE_COMPANY_BUYER_REQUIRED';

    protected int $status = 422;

    public function __construct(string $message = 'Select the company this purchase is for before checking out.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
