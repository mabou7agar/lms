<?php

use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Exceptions\ProtectedRoleException;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IdentitySeeder::class);
});

function roleManager(): User
{
    $user = User::factory()->create();
    $user->assignRole(SpatieRole::findByName('admin', 'web'));

    return $user;
}

it('lets a role manager view and create roles', function () {
    $admin = roleManager();

    expect(Gate::forUser($admin)->allows('viewAny', SpatieRole::class))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('create', SpatieRole::class))->toBeTrue();
});

it('forbids role management to a user without the permission', function () {
    $student = User::factory()->create();
    $student->assignRole(SpatieRole::findByName('student', 'web'));

    expect(Gate::forUser($student)->allows('viewAny', SpatieRole::class))->toBeFalse()
        ->and(Gate::forUser($student)->allows('create', SpatieRole::class))->toBeFalse();
});

it('lets a manager edit or delete a custom role but never a protected system role', function () {
    $admin = roleManager();
    $custom = SpatieRole::findOrCreate('marketing_ops_test', 'web');
    $adminRole = SpatieRole::findByName('admin', 'web');

    expect(Gate::forUser($admin)->allows('update', $custom))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $custom))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $adminRole))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('delete', $adminRole))->toBeFalse();
});

it('blocks deletion of a protected system role at the model layer', function () {
    $superRole = SpatieRole::findByName('super_admin', 'web');

    expect(fn () => $superRole->delete())->toThrow(ProtectedRoleException::class);
});

it('allows deletion of a non-protected custom role at the model layer', function () {
    $custom = SpatieRole::findOrCreate('temp_role_test', 'web');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $custom->delete();

    expect(SpatieRole::where('name', 'temp_role_test')->exists())->toBeFalse();
});