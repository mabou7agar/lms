<?php

use App\Contexts\Analytics\Database\Seeders\AnalyticsSeeder;
use App\Contexts\Analytics\Enums\AnalyticsPermission;
use App\Contexts\Commerce\Database\Seeders\CommerceSeeder;
use App\Contexts\Commerce\Enums\CommercePermission;
use App\Contexts\Learning\Database\Seeders\LearningSeeder;
use App\Contexts\Learning\Enums\LearningPermission;
use App\Domains\Assessment\Database\Seeders\AssessmentSeeder;
use App\Domains\Assessment\Enums\AssessmentPermission;
use App\Domains\Assessment\Enums\AssignmentPermission;
use App\Domains\Authoring\Database\Seeders\AuthoringSeeder;
use App\Domains\Authoring\Enums\AuthoringPermission;
use App\Domains\Catalog\Database\Seeders\CatalogSeeder;
use App\Domains\Catalog\Enums\CatalogPermission;
use App\Domains\Certification\Database\Seeders\CertificationSeeder;
use App\Domains\Certification\Enums\CertificationPermission;
use App\Domains\Crm\Database\Seeders\CrmSeeder;
use App\Domains\Crm\Enums\CrmPermission;
use App\Domains\Live\Database\Seeders\LiveSeeder;
use App\Domains\Live\Enums\LivePermission;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Enums\Permission as IdentityPermission;
use App\Platform\Identity\Models\User;
use App\Platform\Notifications\Database\Seeders\NotificationsSeeder;
use App\Platform\Notifications\Enums\NotificationsPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/**
 * Every permission declared across all domain enums. Aggregated in the test layer (deptrac
 * analyses app/, not tests/) so the whole 39-permission surface is pinned without adding a
 * cross-domain aggregator to the application.
 *
 * @return list<string>
 */
function rbacDeclaredPermissions(): array
{
    return [
        ...AnalyticsPermission::values(),
        ...CommercePermission::values(),
        ...LearningPermission::values(),
        ...AssessmentPermission::values(),
        ...AssignmentPermission::values(),
        ...AuthoringPermission::values(),
        ...CatalogPermission::values(),
        ...CertificationPermission::values(),
        ...CrmPermission::values(),
        ...LivePermission::values(),
        ...IdentityPermission::values(),
        ...NotificationsPermission::values(),
    ];
}

function rbacSeedAllPermissions(): void
{
    // Same order as DatabaseSeeder so cross-seeder dependencies hold.
    test()->seed(IdentitySeeder::class);
    test()->seed(CatalogSeeder::class);
    test()->seed(AuthoringSeeder::class);
    test()->seed(AssessmentSeeder::class);
    test()->seed(LearningSeeder::class);
    test()->seed(CommerceSeeder::class);
    test()->seed(CertificationSeeder::class);
    test()->seed(LiveSeeder::class);
    test()->seed(CrmSeeder::class);
    test()->seed(AnalyticsSeeder::class);
    test()->seed(NotificationsSeeder::class);
}

it('seeds every declared domain permission exactly once on the web guard', function () {
    rbacSeedAllPermissions();

    foreach (rbacDeclaredPermissions() as $name) {
        $rows = Permission::where('name', $name)->where('guard_name', 'web')->get();
        expect($rows)->toHaveCount(1, "Expected exactly one web-guard row for permission [{$name}].");
    }
});

it('keeps the Shield config in its safe, non-destructive mode', function () {
    expect(config('filament-shield.policies.generate'))->toBeFalse()
        ->and(config('filament-shield.permissions.generate'))->toBeFalse()
        ->and(config('filament-shield.super_admin.define_via_gate'))->toBeTrue()
        ->and(config('filament-shield.auth_provider_model'))->toBe(User::class);
});

it('grants super_admin every ability through the central gate bypass', function () {
    $this->seed(IdentitySeeder::class);

    $root = User::factory()->create();
    $root->assignRole(SpatieRole::findByName('super_admin', 'web'));

    expect(SpatieRole::findByName('super_admin', 'web')->permissions)->toBeEmpty()
        ->and(Gate::forUser($root)->allows('catalog.courses.manage'))->toBeTrue()
        ->and(Gate::forUser($root)->allows('any.unregistered.ability'))->toBeTrue();
});

it('does not grant the central bypass to non super_admin users', function () {
    $this->seed(IdentitySeeder::class);

    $plain = User::factory()->create();
    $plain->assignRole(SpatieRole::findByName('student', 'web'));

    expect(Gate::forUser($plain)->allows('catalog.courses.manage'))->toBeFalse()
        ->and(Gate::forUser($plain)->allows('any.unregistered.ability'))->toBeFalse();
});

it('gives a newly created custom role exactly the permissions assigned to it', function () {
    $this->seed(IdentitySeeder::class);
    $this->seed(CommerceSeeder::class);

    $role = SpatieRole::findOrCreate('finance_ops_test', 'web');
    $role->givePermissionTo('commerce.products.manage');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $finance = User::factory()->create();
    $finance->assignRole($role);

    expect($finance->hasPermission('commerce.products.manage'))->toBeTrue()
        ->and($finance->hasPermission('catalog.courses.manage'))->toBeFalse();
});