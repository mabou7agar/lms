<?php

declare(strict_types=1);

use App\Domains\Crm\Models\Department;
use App\Domains\Crm\Models\Organization;
use App\Domains\Crm\Models\OrganizationMember;
use App\Domains\Crm\Models\Team;
use App\Domains\Crm\Services\ManagerScope;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => app(TenantContext::class)->forget());
afterEach(fn () => app(TenantContext::class)->forget());

/**
 * Manager-hierarchy scope resolution. Authority comes from OWNER/ADMIN role (whole org) or from being
 * assigned as a department/team manager — never from the plain `member`/`manager` role alone.
 */
function member(Organization $org, User $user, string $role = 'member', ?int $departmentId = null): OrganizationMember
{
    return OrganizationMember::create([
        'organization_id' => $org->id,
        'department_id' => $departmentId,
        'user_id' => $user->id,
        'email' => $user->email,
        'role' => $role,
        'status' => 'active',
    ]);
}

it('resolves an org admin to the whole organization', function (): void {
    $org = Organization::factory()->create();
    $admin = User::factory()->create();
    member($org, $admin, 'admin');

    $deptA = Department::create(['organization_id' => $org->id, 'name' => 'A']);
    $l1 = member($org, User::factory()->create(), 'member', $deptA->id);
    $l2 = member($org, User::factory()->create());

    $scope = app(ManagerScope::class)->forUser($admin->id, $org->id);

    expect($scope->viewAll)->toBeTrue()
        ->and($scope->isManager())->toBeTrue()
        ->and($scope->coversMember($l1->id))->toBeTrue()
        ->and($scope->coversMember($l2->id))->toBeTrue();
});

it('resolves a department manager to only their department (and NOT another department)', function (): void {
    $org = Organization::factory()->create();

    $mgrUser = User::factory()->create();
    $deptA = Department::create(['organization_id' => $org->id, 'name' => 'A', 'manager_id' => $mgrUser->id]);
    $deptB = Department::create(['organization_id' => $org->id, 'name' => 'B']);

    member($org, $mgrUser, 'member', $deptA->id);
    $inA = member($org, User::factory()->create(), 'member', $deptA->id);
    $inB = member($org, User::factory()->create(), 'member', $deptB->id);

    $scope = app(ManagerScope::class)->forUser($mgrUser->id, $org->id);

    expect($scope->viewAll)->toBeFalse()
        ->and($scope->isManager())->toBeTrue()
        ->and($scope->departmentIds)->toBe([$deptA->id])
        ->and($scope->coversDepartment($deptA->id))->toBeTrue()
        ->and($scope->coversDepartment($deptB->id))->toBeFalse()   // negative: never another dept
        ->and($scope->coversMember($inA->id))->toBeTrue()
        ->and($scope->coversMember($inB->id))->toBeFalse();          // negative: never another dept's member
});

it('resolves a team manager to only their team', function (): void {
    $org = Organization::factory()->create();

    $mgrUser = User::factory()->create();
    $team = Team::create(['organization_id' => $org->id, 'name' => 'X', 'manager_id' => $mgrUser->id]);
    $otherTeam = Team::create(['organization_id' => $org->id, 'name' => 'Y']);

    $onTeam = member($org, User::factory()->create());
    $offTeam = member($org, User::factory()->create());
    $team->members()->attach($onTeam->id, ['role' => 'member']);

    $scope = app(ManagerScope::class)->forUser($mgrUser->id, $org->id);

    expect($scope->teamIds)->toBe([$team->id])
        ->and($scope->isManager())->toBeTrue()
        ->and($scope->coversTeam($team->id))->toBeTrue()
        ->and($scope->coversTeam($otherTeam->id))->toBeFalse()
        ->and($scope->coversMember($onTeam->id))->toBeTrue()
        ->and($scope->coversMember($offTeam->id))->toBeFalse();
});

it('does NOT treat a plain member (or unassigned manager-role) as a manager', function (): void {
    $org = Organization::factory()->create();
    $plain = User::factory()->create();
    member($org, $plain, 'member');

    $scope = app(ManagerScope::class)->forUser($plain->id, $org->id);

    expect($scope->isManager())->toBeFalse()
        ->and($scope->viewAll)->toBeFalse()
        ->and($scope->memberIds)->toBe([]);
});
