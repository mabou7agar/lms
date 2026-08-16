<?php

namespace App\Platform\Shared\Commerce\Exceptions;

/**
 * The product's reassignment policy forbids taking this seat back. Reclaiming a seat destroys the
 * holder's record, so a product may be sold on the promise that a licence stays with its first
 * holder, or may only be recalled before they start or before they pass a progress mark.
 */
class SeatReassignmentBlockedException extends CompanyEntitlementException
{
    protected string $errorCode = 'COMMERCE_SEAT_REASSIGNMENT_BLOCKED';

    protected int $status = 409;

    public function __construct(string $message = 'This purchase does not allow the seat to be reassigned.', array $details = [])
    {
        parent::__construct($message, $details);
    }
}
