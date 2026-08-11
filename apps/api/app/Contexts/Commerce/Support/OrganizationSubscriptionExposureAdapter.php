<?php

namespace App\Contexts\Commerce\Support;

use App\Contexts\Commerce\Actions\Subscription\ChangeOrganizationSeatsAction;
use App\Contexts\Commerce\Exceptions\SubscriptionException;
use App\Contexts\Commerce\Services\OrganizationSubscriptionService;
use App\Platform\Shared\Enterprise\Contracts\OrganizationSubscriptionPort;
use App\Platform\Shared\Enterprise\Data\OrganizationSeatSummary;
use App\Platform\Shared\Seats\Exceptions\SeatDowngradeBelowAssignedException;

/**
 * Commerce-side implementation of the Shared OrganizationSubscriptionPort — the ONLY place a Commerce
 * subscription is exposed to the CRM enterprise portal. Thin orchestration over the existing
 * OrganizationSubscriptionService (read) and ChangeOrganizationSeatsAction (resize); it adds no
 * business rule, it only translates the Commerce vocabulary into the CRM-neutral Shared shape.
 *
 * Tenancy is still enforced by those collaborators (OrganizationSubscriptionGuard): the target
 * organization must equal the request's active tenant, so a forged id is inert here too.
 */
final class OrganizationSubscriptionExposureAdapter implements OrganizationSubscriptionPort
{
    public function __construct(
        private readonly OrganizationSubscriptionService $subscriptions,
        private readonly ChangeOrganizationSeatsAction $resize,
    ) {}

    public function seatSummary(int $organizationId): ?OrganizationSeatSummary
    {
        $summary = $this->subscriptions->summary($organizationId);

        if ($summary === null) {
            return null;
        }

        return new OrganizationSeatSummary(
            subscriptionPublicId: $summary['public_id'],
            status: $summary['status'],
            purchased: $summary['seats']['purchased'],
            used: $summary['seats']['used'],
            available: $summary['seats']['available'],
        );
    }

    public function resizeSeats(int $organizationId, int $newSeats): bool
    {
        $subscription = $this->subscriptions->organizationSubscription($organizationId);

        if ($subscription === null) {
            return false;
        }

        // Reject a downgrade below the currently-assigned count with the CRM-neutral Shared exception,
        // so the CRM portal never has to know the Commerce exception vocabulary. The underlying action
        // re-checks this atomically under a row lock.
        $used = $this->subscriptions->seatUsage($subscription)['used'];

        if ($newSeats < $used) {
            throw new SeatDowngradeBelowAssignedException($newSeats, $used);
        }

        try {
            $this->resize->execute($subscription, $newSeats);
        } catch (SubscriptionException) {
            // A concurrent assignment raised the assigned count between the check and the resize.
            throw new SeatDowngradeBelowAssignedException($newSeats, $this->subscriptions->seatUsage($subscription->fresh() ?? $subscription)['used']);
        }

        return true;
    }
}
