<?php

namespace App\Platform\Shared\Seats\Contracts;

use App\Platform\Shared\Seats\Data\SeatCounts;
use App\Platform\Shared\Seats\Exceptions\SeatDowngradeBelowAssignedException;

/**
 * The complete surface Commerce is allowed to know about the CRM seat infrastructure. Owned by
 * Shared, implemented by the CRM domain (which owns seat_pools / seat_assignments / the atomic
 * SeatService), consumed by Commerce so an organization subscription can provision and drive a seat
 * pool without importing a single CRM Eloquent class.
 *
 * This is the single Commerce→CRM seam for seats. Every parameter and return value is a scalar, a
 * list of scalars, or a Shared DTO — never a CRM model. Members are identified by their internal
 * `organization_members.id`; pools by `seat_pools.id`; users by the platform user id.
 *
 * The actual seat mechanics (pool locking, over-allocation prevention via SeatPoolExhaustedException,
 * idempotent assign/revoke) live behind this port in CRM and are reused verbatim — this contract
 * only moves the dependency behind a Shared seam, it does not reimplement seats.
 *
 * TENANCY (T1, later): seat_pools and organization_members are tenant-owned; every implementation of
 * this port must constrain its lookups to the active tenant once tenant scoping lands.
 */
interface SeatProvisioningPort
{
    /**
     * Provision a seat pool of the given capacity for an organization and return its id. Sized by the
     * subscription's purchased seat quantity.
     */
    public function provisionPool(int $organizationId, string $name, int $seats): int;

    /**
     * Resize a pool's capacity. Runs the assigned-count check and the resize atomically under a row
     * lock so the assigned count cannot change between the two.
     *
     * A missing pool is a silent no-op (the linkage has already gone). A downgrade below the number of
     * seats currently assigned is refused — assigned members are never silently evicted.
     *
     * @throws SeatDowngradeBelowAssignedException when $newSeats is below the active assignment count.
     */
    public function resizePool(int $seatPoolId, int $newSeats): void;

    /**
     * Assign a member a seat in the pool. Over-allocation is prevented by the CRM seat layer, which
     * throws SeatPoolExhaustedException when the pool is full. Idempotent for an already-seated member.
     */
    public function assignSeat(int $seatPoolId, int $memberId): void;

    /** Release a member's seat in the pool. Idempotent: releasing a seat the member does not hold is a no-op. */
    public function releaseSeat(int $seatPoolId, int $memberId): void;

    /**
     * Release every active seat a member holds across all pools — the "deactivating an employee
     * releases their seat" policy. Each affected pool's used-seat count is decremented atomically.
     */
    public function releaseAllSeatsForMember(int $memberId): void;

    /**
     * Seat usage for the pool: purchased (capacity), assigned (active seats), available (free). Returns
     * an all-zero snapshot when the pool is missing.
     */
    public function seatCounts(int $seatPoolId): SeatCounts;

    /**
     * The seat pool ids in which the given platform user currently holds an active seat, resolved
     * user → organization_members(user_id) → seat_assignments(active) → seat_pool_id. Drives
     * entitlement propagation to seated employees.
     *
     * @return list<int>
     */
    public function activeSeatPoolIdsForUser(int $userId): array;
}
