<?php

namespace App\Platform\Shared\Commerce\Exceptions;

/**
 * Every seat in the purchased pool is taken. Refusing is the point: a company that bought ten seats
 * gets ten, and the eleventh employee needs either a revoked seat or a bigger purchase.
 */
class CompanySeatsExhaustedException extends CompanyEntitlementException
{
    protected string $errorCode = 'COMMERCE_COMPANY_SEATS_EXHAUSTED';

    protected int $status = 409;

    public function __construct(string $message = 'No seats are left in this purchase. Revoke a seat or buy more to continue.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
