<?php

namespace App\Platform\Shared\Commerce\Exceptions;

use App\Platform\Shared\Exceptions\BaseDomainException;

/**
 * Refusals raised while a manager is handing out seats from a company purchase.
 *
 * These live in Shared rather than in Commerce because they cross the seam: Commerce throws them
 * behind CompanyEntitlementPort, and the CRM manager controller lets them render. Extending
 * BaseDomainException means each one already carries the code, status and message the portal needs
 * to tell the manager exactly why the action was refused — neither side has to translate the other's
 * vocabulary, and neither imports the other.
 */
abstract class CompanyEntitlementException extends BaseDomainException {}
