<?php

use App\Contexts\Analytics\Database\Seeders\AnalyticsSeeder;
use App\Contexts\Analytics\Enums\AnalyticsPermission;
use App\Contexts\Learning\Database\Seeders\LearningSeeder;
use App\Contexts\Learning\Enums\LearningPermission;
use App\Domains\Catalog\Database\Seeders\CatalogSeeder;
use App\Domains\Catalog\Enums\CatalogPermission;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

/**
 * Permission seeding is load-bearing for authorization: a permission row that is missing, seeded
 * against the wrong guard, or duplicated turns a correct authorization check into a silent denial
 * (or, worse, a silent grant). These tests pin the invariants rather than any particular seeder's
 * internals.
 */
it('creates every declared permission exactly once, on the web guard', function () {
    $this->seed(IdentitySeeder::class);
    $this->seed(CatalogSeeder::class);
    $this->seed(LearningSeeder::class);
    $this->seed(AnalyticsSeeder::class);

    $declared = [
        ...CatalogPermission::values(),
        ...LearningPermission::values(),
        ...AnalyticsPermission::values(),
    ];

    foreach ($declared as $name) {
        $rows = Permission::where('name', $name)->get();

        expect($rows)->toHaveCount(1, "Expected exactly one row for permission [{$name}].")
            ->and($rows->first()->guard_name)->toBe('web');
    }
});

it('is idempotent — reseeding creates no duplicates', function () {
    $this->seed(IdentitySeeder::class);
    $this->seed(CatalogSeeder::class);
    $this->seed(AnalyticsSeeder::class);

    $permissions = Permission::count();
    $grants = DB::table('role_has_permissions')->count();

    $this->seed(CatalogSeeder::class);
    $this->seed(AnalyticsSeeder::class);

    // `database:fresh --seed` is not the only way these run; a partial reseed on an existing
    // database must not accumulate rows or fail on a unique constraint.
    expect(Permission::count())->toBe($permissions)
        ->and(DB::table('role_has_permissions')->count())->toBe($grants);
});

it('leaves no grant pointing at a permission or role that does not exist', function () {
    $this->seed(IdentitySeeder::class);
    $this->seed(CatalogSeeder::class);
    $this->seed(LearningSeeder::class);
    $this->seed(AnalyticsSeeder::class);

    $orphanPermissions = DB::table('role_has_permissions as rhp')
        ->leftJoin('permissions as p', 'p.id', '=', 'rhp.permission_id')
        ->whereNull('p.id')
        ->count();

    $orphanRoles = DB::table('role_has_permissions as rhp')
        ->leftJoin('roles as r', 'r.id', '=', 'rhp.role_id')
        ->whereNull('r.id')
        ->count();

    expect($orphanPermissions)->toBe(0)->and($orphanRoles)->toBe(0);
});

it('resolves a seeded permission under the sanctum guard, not just web', function () {
    $this->seed(IdentitySeeder::class);
    $this->seed(AnalyticsSeeder::class);

    $admin = User::factory()->create();
    $admin->assignRole(SpatieRole::findByName('admin', 'web'));

    // The defect this pins: $user->can() resolves the REQUEST's guard, which under auth:sanctum is
    // not the web guard permissions are seeded against — so it answers false for a genuine holder.
    // Actor::hasPermission() pins 'web' and is the reason authorization works across the API.
    expect($admin->hasPermission(AnalyticsPermission::ViewAnalytics->value))->toBeTrue()
        ->and($admin->hasPermission('analytics.does.not.exist'))->toBeFalse();
});

it('does not grant an instructor platform-wide analytics or exports by default', function () {
    $this->seed(IdentitySeeder::class);
    $this->seed(AnalyticsSeeder::class);

    $instructor = User::factory()->create();
    $instructor->assignRole(SpatieRole::findByName('instructor', 'web'));

    // analytics.view is scoped to their own courses by InstructorScope. Export and revenue are
    // deliberately withheld: an instructor must not be able to pull platform data or see revenue.
    expect($instructor->hasPermission(AnalyticsPermission::ViewAnalytics->value))->toBeTrue()
        ->and($instructor->hasPermission(AnalyticsPermission::ExportAnalytics->value))->toBeFalse()
        ->and($instructor->hasPermission(AnalyticsPermission::ViewRevenue->value))->toBeFalse();
});

it('grants super_admin nothing directly and relies on the role bypass', function () {
    $this->seed(IdentitySeeder::class);
    $this->seed(AnalyticsSeeder::class);

    $root = User::factory()->create();
    $root->assignRole(SpatieRole::findByName('super_admin', 'web'));

    // Documenting the design: super_admin holds no permission rows. Access comes from the before()
    // hook on each policy, so a missing permission seeder can never quietly lock the platform's
    // last administrator out — and equally, a permission check alone is never enough to authorize
    // super_admin, which is why InstructorScope branches on the role explicitly.
    expect(SpatieRole::findByName('super_admin', 'web')->permissions)->toBeEmpty()
        ->and($root->hasRole('super_admin'))->toBeTrue();
});
