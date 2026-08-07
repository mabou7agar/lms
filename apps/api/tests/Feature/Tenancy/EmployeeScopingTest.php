<?php

declare(strict_types=1);

use App\Platform\Identity\Models\User;
use App\Platform\Shared\Tenancy\Concerns\BelongsToTenant;
use App\Platform\Shared\Tenancy\RequestTenantResolver;
use App\Platform\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Proves the employee-scoping seam added in Sprint 0.5: an authenticated user's `organization_id`
 * (new column) drives the active tenant through the real RequestTenantResolver, isolating each
 * employee's data on a throwaway tenant-scoped model — no production model is touched.
 */
class EmployeeScopeTestModel extends Model
{
    use BelongsToTenant;

    protected $table = 'employee_scope_test';

    public $timestamps = false;

    protected $guarded = [];
}

beforeEach(function (): void {
    Schema::create('employee_scope_test', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('organization_id')->nullable();
        $table->string('name');
    });

    app(TenantContext::class)->forget();
});

afterEach(function (): void {
    Schema::dropIfExists('employee_scope_test');
    app(TenantContext::class)->forget();
});

/** Seed rows across organizations without the creating hook stamping the active tenant. */
function seedAcrossOrgs(array $rows): void
{
    app(TenantContext::class)->runWithoutTenancy(static fn () => EmployeeScopeTestModel::insert($rows));
}

it('derives the active tenant from the authenticated employee organization and scopes their data', function (): void {
    seedAcrossOrgs([
        ['organization_id' => 1, 'name' => 'ours'],
        ['organization_id' => 2, 'name' => 'theirs'],
    ]);

    $employee = User::factory()->create(['organization_id' => 1]);
    $this->actingAs($employee);
    app(TenantContext::class)->forget(); // re-arm lazy resolution now that the user is acting

    expect(app(RequestTenantResolver::class)->resolve())->not->toBeNull()
        ->and(app(RequestTenantResolver::class)->resolve()->value)->toEqual(1)
        ->and(EmployeeScopeTestModel::pluck('name')->all())->toBe(['ours']);
});

it('isolates two employees who belong to different organizations', function (): void {
    seedAcrossOrgs([
        ['organization_id' => 10, 'name' => 'a10'],
        ['organization_id' => 20, 'name' => 'b20'],
    ]);

    $alice = User::factory()->create(['organization_id' => 10]);
    $bob = User::factory()->create(['organization_id' => 20]);

    $this->actingAs($alice);
    app(TenantContext::class)->forget();
    expect(EmployeeScopeTestModel::pluck('name')->all())->toBe(['a10']);

    $this->actingAs($bob);
    app(TenantContext::class)->forget();
    expect(EmployeeScopeTestModel::pluck('name')->all())->toBe(['b20']);
});

it('leaves an employee with no organization un-scoped (backward compatible)', function (): void {
    seedAcrossOrgs([
        ['organization_id' => 1, 'name' => 'a'],
        ['organization_id' => 2, 'name' => 'b'],
    ]);

    $user = User::factory()->create(); // organization_id is null
    $this->actingAs($user);
    app(TenantContext::class)->forget();

    expect(app(RequestTenantResolver::class)->resolve())->toBeNull()
        ->and(EmployeeScopeTestModel::count())->toBe(2);
});

it('stamps a new record with the acting employee organization', function (): void {
    $employee = User::factory()->create(['organization_id' => 5]);
    $this->actingAs($employee);
    app(TenantContext::class)->forget();

    $row = EmployeeScopeTestModel::create(['name' => 'x']);

    expect((int) $row->organization_id)->toBe(5);
});
