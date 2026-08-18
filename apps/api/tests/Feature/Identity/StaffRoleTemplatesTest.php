<?php

use App\Platform\Identity\Models\User;
use Database\Seeders\StaffRoleTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

function seedTemplatePermissions(): void
{
    foreach (StaffRoleTemplatesSeeder::templates() as $permissions) {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

it('seeds each staff role template with exactly its declared permissions', function () {
    seedTemplatePermissions();
    (new StaffRoleTemplatesSeeder)->run();

    foreach (StaffRoleTemplatesSeeder::templates() as $roleName => $permissions) {
        $role = SpatieRole::where('name', $roleName)->where('guard_name', 'web')->first();
        expect($role)->not->toBeNull("Expected staff role [{$roleName}] to be seeded.");
        expect($role->permissions->pluck('name')->sort()->values()->all())
            ->toBe(collect($permissions)->sort()->values()->all());
    }
});

it('is idempotent and never overwrites an administrator-customised template', function () {
    seedTemplatePermissions();
    (new StaffRoleTemplatesSeeder)->run();
    $roleCount = SpatieRole::count();

    $support = SpatieRole::findByName('support_agent', 'web');
    $support->syncPermissions(['crm.view']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    (new StaffRoleTemplatesSeeder)->run();

    expect(SpatieRole::count())->toBe($roleCount)
        ->and(SpatieRole::findByName('support_agent', 'web')->permissions->pluck('name')->all())
        ->toBe(['crm.view']);
});

it('keeps finance and support scoped to their function (least privilege)', function () {
    seedTemplatePermissions();
    (new StaffRoleTemplatesSeeder)->run();

    $finance = User::factory()->create();
    $finance->assignRole(SpatieRole::findByName('finance_manager', 'web'));

    $support = User::factory()->create();
    $support->assignRole(SpatieRole::findByName('support_agent', 'web'));

    expect($finance->hasPermission('commerce.orders.view'))->toBeTrue()
        ->and($finance->hasPermission('catalog.courses.manage'))->toBeFalse()
        ->and($support->hasPermission('crm.view'))->toBeTrue()
        ->and($support->hasPermission('commerce.refunds.manage'))->toBeFalse();
});
