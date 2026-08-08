<?php

namespace App\Contexts\Commerce\Services;

use App\Contexts\Commerce\Exceptions\SubscriptionException;
use App\Contexts\Commerce\Models\Subscription;
use App\Platform\Shared\Seats\Contracts\SeatProvisioningPort;
use App\Platform\Shared\Services\BaseService;

/**
 * Organization-subscription operations and read-only visibility surfaces.
 *
 * Write operations (assign/unassign an employee, release an employee's seats on deactivation) are
 * thin orchestration over the Shared SeatProvisioningPort → CRM SeatService, which owns the atomic
 * seat mechanics (pool locking, over-allocation prevention, idempotency). This service adds only the
 * subscription context and the domain guards, and never imports a CRM model — employees cross this
 * boundary as scalar `organization_members.id` values.
 *
 * Read operations (organizationSubscription, seatUsage, summary) back the admin/manager visibility of
 * the org subscription + seat usage. They are pure reads — no writes, no gateway I/O.
 *
 * AUTHORIZATION (later): callers must gate these to an organization admin (and manager where allowed).
 * That policy check belongs in the HTTP/Filament layer that invokes this service and is out of scope
 * for this phase.
 *
 * TENANCY (T1, later): every query here filters on organization_id / seat_pool_id, which are
 * tenant-owned. When tenant scoping lands these must be constrained to the active tenant.
 */
class OrganizationSubscriptionService extends BaseService
{
    public function __construct(
        private readonly SeatProvisioningPort $seats,
    ) {}

    /**
     * Assign an employee (organization member, identified by its `organization_members.id`) a seat on
     * the subscription. Over-allocation is prevented by the CRM seat layer (SeatPoolExhaustedException
     * when the pool is full).
     */
    public function assignEmployee(Subscription $subscription, int $memberId): void
    {
        $this->seats->assignSeat($this->requirePoolId($subscription), $memberId);
    }

    /** Release an employee's seat on the subscription (idempotent). */
    public function unassignEmployee(Subscription $subscription, int $memberId): void
    {
        $this->seats->releaseSeat($this->requirePoolId($subscription), $memberId);
    }

    /**
     * Release every seat a deactivated employee holds across all pools — the "deactivating an
     * employee releases their seat" policy. Intended to be called from the CRM member-removal flow.
     */
    public function releaseSeatsForMember(int $memberId): void
    {
        $this->seats->releaseAllSeatsForMember($memberId);
    }

    /**
     * The organization's current access-granting subscription (verified against the live clock via
     * isActiveNow), or null when none is active right now.
     */
    public function organizationSubscription(int $organizationId): ?Subscription
    {
        $candidates = Subscription::query()
            ->where('organization_id', $organizationId)
            ->whereIn('status', Subscription::accessGrantingStatusValues())
            ->with('plan')
            ->latest('id')
            ->get();

        foreach ($candidates as $subscription) {
            if ($subscription->isActiveNow()) {
                return $subscription;
            }
        }

        return null;
    }

    /**
     * Seat usage for an organization subscription: purchased (capacity), used (assigned), available.
     *
     * @return array{purchased: int, used: int, available: int}
     */
    public function seatUsage(Subscription $subscription): array
    {
        $counts = $this->seats->seatCounts($this->requirePoolId($subscription));

        return [
            'purchased' => $counts->purchased,
            'used' => $counts->assigned,
            'available' => $counts->available,
        ];
    }

    /**
     * Read-only summary of an organization's subscription + seat usage for an admin/manager surface.
     * Returns null when the organization has no active subscription.
     *
     * @return array{
     *     subscription_id: int,
     *     public_id: string,
     *     status: string,
     *     plan_id: int,
     *     current_period_end: string|null,
     *     seats: array{purchased: int, used: int, available: int}
     * }|null
     */
    public function summary(int $organizationId): ?array
    {
        $subscription = $this->organizationSubscription($organizationId);

        if ($subscription === null) {
            return null;
        }

        return [
            'subscription_id' => (int) $subscription->getKey(),
            'public_id' => (string) $subscription->public_id,
            'status' => $subscription->statusEnum()->value,
            'plan_id' => $subscription->planId(),
            'current_period_end' => $subscription->currentPeriodEnd()?->toIso8601String(),
            'seats' => $this->seatUsage($subscription),
        ];
    }

    /** Guard: the subscription must be an organization subscription with a provisioned pool. */
    private function requirePoolId(Subscription $subscription): int
    {
        if (! $subscription->isOrganization() || $subscription->seatPoolId() === null) {
            throw SubscriptionException::notAnOrganizationSubscription($subscription->public_id);
        }

        return (int) $subscription->seatPoolId();
    }
}
