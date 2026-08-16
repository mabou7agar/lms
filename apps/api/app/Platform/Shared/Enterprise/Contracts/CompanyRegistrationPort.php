<?php

namespace App\Platform\Shared\Enterprise\Contracts;

use App\Platform\Shared\Enterprise\Data\CompanyRegistrationInput;

/**
 * Creates the organization behind a company account and makes the registering user its owner.
 *
 * Registration lives in Identity, but organizations and memberships belong to CRM. This port is the
 * seam: Identity states the intent and receives an id back, so it never imports a CRM model. CRM owns
 * the only implementation.
 */
interface CompanyRegistrationPort
{
    /**
     * Create the organization and enrol `$ownerUserId` as its active owner.
     *
     * Implementations must be atomic — a half-made company would leave a user who can neither manage
     * an organization nor register a new one under the same name.
     *
     * @return int the new organization's internal id, to stamp on the user
     */
    public function registerCompany(CompanyRegistrationInput $input, int $ownerUserId, string $ownerEmail): int;
}
