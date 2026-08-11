<?php

namespace App\Platform\Shared\Enterprise\Contracts;

use App\Platform\Shared\Enterprise\Data\OrganizationSeatSummary;
use App\Platform\Shared\Seats\Exceptions\SeatDowngradeBelowAssignedException;

/**
 * The Commerce→CRM seam for the enterprise portal's SUBSCRIPTION-LEVEL seat surface. DECLARED here in
 * Shared, IMPLEMENTED by Commerce (thin exposure over OrganizationSubscriptionService +
 * ChangeOrganizationSeatsAction), CONSUMED by the CRM enterprise portal so CRM can show seat usage and
 * resize purchased capacity without importing a Commerce model.
 *
 * TENANCY: the organization id MUST be the request's resolved tenant. Commerce re-authorizes it
 * against the active tenant (OrganizationSubscriptionGuard) before any read or resize, so a forged id
 * is inert.
 *
 * Seat ASSIGN / RELEASE / HISTORY are NOT here: those are pure CRM seat mechanics (CRM owns
 * seat_pools / seat_assignments) and stay inside CRM. This port only exposes what Commerce owns — the
 * subscription's purchased capacity and its resize/downgrade rule.
 */
interface OrganizationSubscriptionPort
{
    /**
     * Seat summary for the organization's active subscription, or null when the organization has no
     * access-granting subscription right now.
     */
    public function seatSummary(int $organizationId): ?OrganizationSeatSummary;

    /**
     * Resize the organization's purchased seat capacity. A downgrade below the number of currently
     * assigned seats is rejected (assigned employees are never silently evicted).
     *
     * @return bool false when the organization has no active subscription to resize; true on success.
     *
     * @throws SeatDowngradeBelowAssignedException on a rejected downgrade.
     */
    public function resizeSeats(int $organizationId, int $newSeats): bool;
}
