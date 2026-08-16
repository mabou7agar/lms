<?php

namespace App\Platform\Shared\Commerce\Contracts;

use App\Platform\Shared\Commerce\Data\CompanyAssignmentOutcome;
use App\Platform\Shared\Commerce\Data\CompanyEntitlementRef;
use App\Platform\Shared\Commerce\Data\CompanySeatHolderRef;
use App\Platform\Shared\Commerce\Data\SeatCandidate;
use App\Platform\Shared\Commerce\Exceptions\CompanyEntitlementNotAssignableException;
use App\Platform\Shared\Commerce\Exceptions\CompanySeatsExhaustedException;
use App\Platform\Shared\Commerce\Exceptions\SeatReassignmentBlockedException;

/**
 * The manager portal's view of what its organization has bought, and the only way it may hand those
 * purchases out.
 *
 * The split of responsibility across this seam is deliberate. CRM owns WHO: it authorizes the caller
 * as a manager of the organization and resolves a member / department / team into the employees that
 * should receive a seat. Commerce owns WHAT and WHETHER: which purchase, how many seats are left,
 * has it expired, does its policy allow a recall — and it is Commerce that grants the courses,
 * through Learning's own port. Neither side imports the other; every argument here is an id or a
 * Shared DTO.
 *
 * Every method takes `$organizationId` and treats it as a filter, not a hint: an entitlement that
 * belongs to another organization is invisible, not merely refused. That is what stops a manager of
 * one company reaching a purchase made by another even if they learn its public id.
 */
interface CompanyEntitlementPort
{
    /**
     * Everything the organization has bought, newest first.
     *
     * @return list<CompanyEntitlementRef>
     */
    public function forOrganization(int $organizationId): array;

    /** One purchase belonging to this organization, or null when there is no such thing. */
    public function findForOrganization(int $organizationId, string $publicId): ?CompanyEntitlementRef;

    /**
     * Who currently holds a seat in this purchase (and, when $includeRevoked, who used to).
     *
     * @return list<CompanySeatHolderRef>
     */
    public function seatHolders(int $organizationId, string $publicId, bool $includeRevoked = false): array;

    /**
     * Give each candidate a seat and grant them the purchase's courses. Idempotent: a candidate who
     * already holds a seat consumes nothing further and is reported under `alreadyAssigned`.
     *
     * @param  list<SeatCandidate>  $candidates
     *
     * @throws CompanyEntitlementNotAssignableException when the purchase has lapsed or was refunded.
     * @throws CompanySeatsExhaustedException when the pool cannot cover the candidates.
     */
    public function assign(int $organizationId, string $publicId, array $candidates): CompanyAssignmentOutcome;

    /**
     * Take a seat back and withdraw the courses it granted. Idempotent for a member who holds no
     * seat.
     *
     * @throws SeatReassignmentBlockedException when the product's policy forbids the recall.
     */
    public function revoke(int $organizationId, string $publicId, int $organizationMemberId): CompanyAssignmentOutcome;
}
