<?php

namespace App\Platform\Shared\Seats\Exceptions;

use RuntimeException;

/**
 * Raised by a SeatProvisioningPort implementation when a pool resize would drop capacity below the
 * number of seats currently assigned — active members would be silently kicked out otherwise.
 *
 * Lives in Shared (not in the owning CRM domain and not in Commerce) so it can cross the seam in
 * the CRM→Shared←Commerce direction the architecture allows: CRM throws it, Commerce catches it and
 * translates it into its own SubscriptionException without either side importing the other. The
 * assigned/requested counts travel on the exception so the caller can build its own message.
 */
class SeatDowngradeBelowAssignedException extends RuntimeException
{
    public function __construct(
        public readonly int $requested,
        public readonly int $assigned,
    ) {
        parent::__construct(
            "Seat pool cannot be resized to [{$requested}] seats: [{$assigned}] are currently assigned."
        );
    }
}
