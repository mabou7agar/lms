<?php

namespace App\Domains\Crm\Ports;

use App\Domains\Crm\Models\OrganizationMember;
use App\Domains\Crm\Models\SeatAssignment;
use App\Domains\Crm\Models\SeatPool;
use App\Domains\Crm\Services\SeatService;
use App\Platform\Shared\Seats\Contracts\SeatProvisioningPort;
use App\Platform\Shared\Seats\Data\SeatCounts;
use App\Platform\Shared\Seats\Exceptions\SeatDowngradeBelowAssignedException;
use App\Platform\Shared\Services\BaseService;

/**
 * CRM-side implementation of the Shared SeatProvisioningPort — the only place a CRM seat model is
 * converted into (or driven from) something Commerce may hold. Commerce reaches the seat pools and
 * assignments exclusively through this adapter, keeping the organization-subscription code CRM-free.
 *
 * The atomic seat mechanics (pool locking, over-allocation prevention, idempotency) are delegated to
 * CRM's SeatService verbatim; the read/model bookkeeping (pool provisioning/resize, assignment
 * counts, cross-pool release, entitlement pool ids) is performed here directly, exactly as before —
 * this class only moves that logic behind the Shared seam, it does not reimplement seats.
 *
 * TENANCY (T1, later): seat_pools and organization_members are tenant-owned; every lookup here must
 * be constrained to the active tenant once tenant scoping lands.
 */
class SeatProvisioningAdapter extends BaseService implements SeatProvisioningPort
{
    public function __construct(
        private readonly SeatService $seats,
    ) {}

    public function provisionPool(int $organizationId, string $name, int $seats): int
    {
        $pool = SeatPool::create([
            'organization_id' => $organizationId,
            'name' => $name,
            'total_seats' => max(0, $seats),
            'used_seats' => 0,
        ]);

        return (int) $pool->getKey();
    }

    public function resizePool(int $seatPoolId, int $newSeats): void
    {
        $this->transaction(function () use ($seatPoolId, $newSeats): void {
            $pool = SeatPool::query()->whereKey($seatPoolId)->lockForUpdate()->first();

            if ($pool === null) {
                return;
            }

            $assigned = SeatAssignment::query()
                ->where('seat_pool_id', $pool->getKey())
                ->whereNull('revoked_at')
                ->count();

            if ($newSeats < $assigned) {
                throw new SeatDowngradeBelowAssignedException($newSeats, $assigned);
            }

            $pool->forceFill(['total_seats' => $newSeats])->save();
        });
    }

    public function assignSeat(int $seatPoolId, int $memberId): void
    {
        $pool = $this->pool($seatPoolId);
        $member = $this->member($memberId);

        if ($pool !== null && $member !== null) {
            $this->seats->assign($pool, $member);
        }
    }

    public function releaseSeat(int $seatPoolId, int $memberId): void
    {
        $pool = $this->pool($seatPoolId);
        $member = $this->member($memberId);

        if ($pool === null || $member === null) {
            return;
        }

        $active = SeatAssignment::query()
            ->where('seat_pool_id', $seatPoolId)
            ->where('member_id', $memberId)
            ->whereNull('revoked_at')
            ->exists();

        if ($active) {
            $this->seats->revoke($pool, $member);
        }
    }

    public function releaseAllSeatsForMember(int $memberId): void
    {
        $this->transaction(function () use ($memberId): void {
            $assignments = SeatAssignment::query()
                ->where('member_id', $memberId)
                ->whereNull('revoked_at')
                ->get();

            foreach ($assignments as $assignment) {
                $pool = SeatPool::query()
                    ->whereKey($assignment->getAttribute('seat_pool_id'))
                    ->lockForUpdate()
                    ->first();

                $assignment->forceFill(['revoked_at' => now()])->save();

                if ($pool !== null && (int) $pool->getAttribute('used_seats') > 0) {
                    $pool->decrement('used_seats');
                }
            }
        });
    }

    public function seatCounts(int $seatPoolId): SeatCounts
    {
        $pool = $this->pool($seatPoolId);

        if ($pool === null) {
            return new SeatCounts(purchased: 0, assigned: 0, available: 0);
        }

        $purchased = (int) $pool->getAttribute('total_seats');

        $assigned = SeatAssignment::query()
            ->where('seat_pool_id', $seatPoolId)
            ->whereNull('revoked_at')
            ->count();

        return new SeatCounts(
            purchased: $purchased,
            assigned: $assigned,
            available: max(0, $purchased - $assigned),
        );
    }

    /**
     * @return list<int>
     */
    public function activeSeatPoolIdsForUser(int $userId): array
    {
        $memberIds = OrganizationMember::query()
            ->where('user_id', $userId)
            ->pluck('id');

        if ($memberIds->isEmpty()) {
            return [];
        }

        return SeatAssignment::query()
            ->whereIn('member_id', $memberIds)
            ->whereNull('revoked_at')
            ->pluck('seat_pool_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function pool(int $seatPoolId): ?SeatPool
    {
        return SeatPool::query()->whereKey($seatPoolId)->first();
    }

    private function member(int $memberId): ?OrganizationMember
    {
        return OrganizationMember::query()->whereKey($memberId)->first();
    }
}
