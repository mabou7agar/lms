<?php

namespace App\Platform\Shared\Commerce\Exceptions;

/**
 * The purchase can no longer be handed out — its access window has elapsed, it was refunded, or it
 * has not started yet. Assigning from it would grant access the company does not currently hold.
 */
class CompanyEntitlementNotAssignableException extends CompanyEntitlementException
{
    protected string $errorCode = 'COMMERCE_ENTITLEMENT_NOT_ASSIGNABLE';

    protected int $status = 409;

    public function __construct(string $message = 'This purchase is no longer active, so seats cannot be assigned from it.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
