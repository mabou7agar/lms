<?php

use App\Contexts\Analytics\Database\Seeders\AnalyticsSeeder;
use App\Contexts\Analytics\Enums\AnalyticsPermission;
use App\Contexts\Learning\Database\Seeders\LearningSeeder;
use App\Contexts\Learning\Enums\LearningPermission;
use App\Domains\Authoring\Database\Seeders\AuthoringSeeder;
use App\Domains\Authoring\Enums\AuthoringPermission;
use App\Domains\Catalog\Database\Seeders\CatalogSeeder;
use App\Domains\Catalog\Enums\CatalogPermission;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

/**
 * Permission checking on the API.
 *
 * Two separate defects are pinned here, both of which made a granted permission silently ineffective:
 *
 *   GUARD  — `$user->can()` resolves the request's guard. Under `auth:sanctum` that is not the
 *            `web` guard permissions are seeded against, so it answers false for a genuine holder.
 *            Actor::hasPermission() pins the guard.
 *   ROWS   — Catalog and Learning defined permission enums that no seeder ever persisted, so their
 *            slugs had no row at all and every check against them could only pass via a
 *            super_admin policy bypass.
 */
function permissionedAdmin(): User
{
    $user = User::factory()->create();
    $user->assignRole(SpatieRole::findByName('admin', 'web'));

    return $user;
}

it('answers true for a permission the admin role genuinely holds', function () {
    $this->seed(IdentitySeeder::class);
    $this->seed(AnalyticsSeeder::class);

    expect(permissionedAdmin()->hasPermission(AnalyticsPermission::ViewAnalytics->value))->toBeTrue();
});

it('answers false rather than throwing for a permission that was never registered', function () {
    $this->seed(IdentitySeeder::class);

    // checkPermissionTo() swallows PermissionDoesNotExist. An authorization check asked about an
    // unknown capability must deny, not 500.
    expect(permissionedAdmin()->hasPermission('nothing.like.this'))->toBeFalse();
});

it('answers false for a real permission the caller was not granted', function () {
    $this->seed(IdentitySeeder::class);
    $this->seed(AnalyticsSeeder::class);

    $instructor = User::factory()->create();
    $instructor->assignRole(SpatieRole::findByName('instructor', 'web'));

    expect($instructor->hasPermission(AnalyticsPermission::ViewRevenue->value))->toBeFalse();
});

it('keeps working through a Sanctum-authenticated request, where can() does not', function () {
    $this->seed(IdentitySeeder::class);
    $this->seed(AnalyticsSeeder::class);
    $admin = permissionedAdmin();

    // The whole point: the check has to hold inside a real request lifecycle, not only in a unit
    // context where the default guard happens to be `web`.
    $this->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/analytics/kpis?metrics[]=enrollments')
        ->assertOk();
});

it('persists every Catalog permission slug', function () {
    $this->seed(IdentitySeeder::class);
    $this->seed(CatalogSeeder::class);

    // catalog.courses.manage is checked three times in CoursePolicy and had no row before this.
    foreach (CatalogPermission::values() as $slug) {
        expect(Permission::where('name', $slug)->where('guard_name', 'web')->exists())
            ->toBeTrue("permission `{$slug}` should be seeded");
    }

    expect(permissionedAdmin()->hasPermission(CatalogPermission::ManageCourses->value))->toBeTrue();
});

it('persists every Learning permission slug', function () {
    $this->seed(IdentitySeeder::class);
    $this->seed(LearningSeeder::class);

    foreach (LearningPermission::values() as $slug) {
        expect(Permission::where('name', $slug)->where('guard_name', 'web')->exists())
            ->toBeTrue("permission `{$slug}` should be seeded");
    }
});

it('grants enrollment administration to admins but not self-view', function () {
    $this->seed(IdentitySeeder::class);
    $this->seed(LearningSeeder::class);
    $admin = permissionedAdmin();

    // A learner's access to their own learning comes from enrollment, not from a permission.
    expect($admin->hasPermission(LearningPermission::ManageEnrollments->value))->toBeTrue()
        ->and($admin->hasPermission(LearningPermission::ViewOwnLearning->value))->toBeFalse();
});

it('keeps the authoring GATE and the authoring PERMISSION as distinct slugs', function () {
    $this->seed(IdentitySeeder::class);
    $this->seed(AuthoringSeeder::class);

    // These are two different things and MUST NOT be unified: `authoring.manage-curriculum` is a
    // gate that consults the permission `authoring.curriculum.manage` inside itself. Giving them
    // the same name makes $user->can() re-enter the gate without its model argument and fatal.
    expect(Permission::where('name', AuthoringPermission::ManageCurriculum->value)->exists())->toBeTrue()
        ->and(Permission::where('name', 'authoring.manage-curriculum')->exists())->toBeFalse();

    expect(permissionedAdmin()->hasPermission(AuthoringPermission::ManageCurriculum->value))->toBeTrue();
});
