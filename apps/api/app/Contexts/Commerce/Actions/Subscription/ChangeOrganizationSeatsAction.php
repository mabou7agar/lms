<?php

namespace App\Contexts\Commerce\Actions\Subscription;

use App\Contexts\Commerce\Exceptions\SubscriptionException;
use App\Contexts\Commerce\Models\Subscription;
use App\Platform\Shared\Actions\BaseAction;
use App\Platform\Shared\Audit\AuditLogger;
use App\Platform\Shared\Seats\Contracts\SeatProvisioningPort;
use App\Platform\Shared\Seats\Exceptions\SeatDowngradeBelowAssignedException;

/**
 * Resize the seat capacity of an organization subscription (seats purchased), keeping the seat pool
 * in step. A DOWNGRADE below the number of seats currently assigned is rejected — assigned employees
 * are never silently kicked out; the admin must unassign first.
 *
 * The capacity check + pool resize run under a row lock inside SeatProvisioningPort::resizePool so the
 * assigned count cannot change between validation and resize. The port signals a rejected downgrade
 * with the CRM-neutral SeatDowngradeBelowAssignedException, which this Commerce action translates into
 * its own SubscriptionException. This changes seat capacity only; it does not re-price the
 * subscription (the recurring amount stays the plan's price), so no charge and no lifecycle transition
 * occur here — only a capacity adjustment and an audit trail.
 *
 * Idempotent: resizing to the current seat count is a no-op.
 */
class ChangeOrganizationSeatsAction extends BaseAction
{
    public function __construct(
        private readonly SeatProvisioningPort $seats,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(Subscription $subscription, int $newSeats): Subscription
    {
        if (! $subscription->isOrganization() || $subscription->seatPoolId() === null) {
            throw SubscriptionException::notAnOrganizationSubscription($subscription->public_id);
        }

        if ($newSeats < 1) {
            throw SubscriptionException::invalidSeatCount($newSeats);
        }

        if ($subscription->seats() === $newSeats) {
            return $subscription; // idempotent no-op
        }

        // Locks the pool, rejects a downgrade below the assigned count, then applies the new capacity.
        // The port raises a CRM-neutral exception on a rejected downgrade; translate it into the
        // Commerce vocabulary here so callers only ever see a SubscriptionException.
        try {
            $this->seats->resizePool((int) $subscription->seatPoolId(), $newSeats);
        } catch (SeatDowngradeBelowAssignedException $e) {
            throw SubscriptionException::seatDowngradeBelowAssigned(
                $subscription->public_id,
                $e->requested,
                $e->assigned,
            );
        }

        $subscription->forceFill(['seats' => $newSeats])->save();

        $this->audit->log('commerce.subscription.org_seats_changed', $subscription, [
            'organization_id' => $subscription->organizationId(),
            'seats' => $newSeats,
        ]);

        return $subscription;
    }
}
