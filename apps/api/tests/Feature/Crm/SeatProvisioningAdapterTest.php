<?php

use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Domains\Crm\Models\SeatPool;
use App\Domains\Crm\Ports\SeatProvisioningAdapter;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Seats\Contracts\SeatProvisioningPort;
use App\Platform\Shared\Seats\Data\SeatCounts;
use App\Platform\Shared\Seats\Exceptions\SeatDowngradeBelowAssignedException;
use App\Domains\Crm\Exceptions\SeatPoolExhaustedException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * The CRM-side implementation of the Shared SeatProvisioningPort. Verifies the container binding and
 * that the real CRM seat mechanics (pool locking, over-allocation, idempotency, cross-pool release)
 * are reachable end-to-end through the port using only scalars and Shared DTOs — no CRM model crosses
 * the seam.
 */

function adapterMember(Organization $org, ?User $user = null, string $email = 'e@corp.com'): OrganizationMember
{
    return OrganizationMember::create([
        'organization_id' => $org->id,
        'user_id' => $user?->id,
        'email' => $email,
        'role' => 'member',
        'status' => 'active',
    ]);
}

it('binds the port to the CRM adapter', function () {
    $port = app(SeatProvisioningPort::class);

    expect($port)->toBeInstanceOf(SeatProvisioningAdapter::class);
});

it('provisions, assigns, counts, and releases a seat through the port', function () {
    $port = app(SeatProvisioningPort::class);
    $org = Organization::factory()->create();

    $poolId = $port->provisionPool($org->id, 'Team', 2);
    $member = adapterMember($org);

    expect($port->seatCounts($poolId))->toEqual(new SeatCounts(purchased: 2, assigned: 0, available: 2));

    $port->assignSeat($poolId, $member->id);

    expect($port->seatCounts($poolId))->toEqual(new SeatCounts(purchased: 2, assigned: 1, available: 1))
        ->and(SeatPool::findOrFail($poolId)->used_seats)->toBe(1);

    // Idempotent: assigning an already-seated member does not double-count.
    $port->assignSeat($poolId, $member->id);
    expect($port->seatCounts($poolId)->assigned)->toBe(1);

    $port->releaseSeat($poolId, $member->id);
    expect($port->seatCounts($poolId))->toEqual(new SeatCounts(purchased: 2, assigned: 0, available: 2));
});

it('enforces over-allocation via the CRM seat layer', function () {
    $port = app(SeatProvisioningPort::class);
    $org = Organization::factory()->create();
    $poolId = $port->provisionPool($org->id, 'Solo', 1);

    $port->assignSeat($poolId, adapterMember($org, email: 'a@corp.com')->id);

    expect(fn () => $port->assignSeat($poolId, adapterMember($org, email: 'b@corp.com')->id))
        ->toThrow(SeatPoolExhaustedException::class);
});

it('rejects a resize below the assigned count with a Shared exception', function () {
    $port = app(SeatProvisioningPort::class);
    $org = Organization::factory()->create();
    $poolId = $port->provisionPool($org->id, 'Team', 3);

    $port->assignSeat($poolId, adapterMember($org, email: 'a@corp.com')->id);
    $port->assignSeat($poolId, adapterMember($org, email: 'b@corp.com')->id);

    try {
        $port->resizePool($poolId, 1);
        $this->fail('Expected a SeatDowngradeBelowAssignedException.');
    } catch (SeatDowngradeBelowAssignedException $e) {
        expect($e->requested)->toBe(1)->and($e->assigned)->toBe(2);
    }

    // Capacity unchanged by the rejected downgrade; a resize at/above the assigned count applies.
    expect(SeatPool::findOrFail($poolId)->total_seats)->toBe(3);

    $port->resizePool($poolId, 2);
    expect(SeatPool::findOrFail($poolId)->total_seats)->toBe(2);
});

it('releases every seat a member holds across pools and exposes the pool ids for a user', function () {
    $port = app(SeatProvisioningPort::class);
    $org = Organization::factory()->create();
    $user = User::factory()->create();

    $poolA = $port->provisionPool($org->id, 'A', 2);
    $poolB = $port->provisionPool($org->id, 'B', 2);
    $member = adapterMember($org, $user);

    $port->assignSeat($poolA, $member->id);
    $port->assignSeat($poolB, $member->id);

    expect($port->activeSeatPoolIdsForUser($user->id))->toEqualCanonicalizing([$poolA, $poolB]);

    $port->releaseAllSeatsForMember($member->id);

    expect($port->seatCounts($poolA)->assigned)->toBe(0)
        ->and($port->seatCounts($poolB)->assigned)->toBe(0)
        ->and(SeatPool::findOrFail($poolA)->used_seats)->toBe(0)
        ->and(SeatPool::findOrFail($poolB)->used_seats)->toBe(0)
        ->and($port->activeSeatPoolIdsForUser($user->id))->toBe([]);
});
