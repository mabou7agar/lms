<?php

use App\Domains\Authoring\Database\Seeders\AuthoringSeeder;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

/**
 * Localization did not weaken the Sprint 0.1 RBAC: editing a localized model (a curriculum Section,
 * which carries title_i18n / summary_i18n) is still gated by the authoring.manage-curriculum gate
 * and the SectionPolicy. A student is denied; the owning instructor and a permissioned admin are
 * allowed. Exercised over the real HTTP update path and confirmed at the policy gate.
 */
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    // AuthoringSeeder grants authoring.curriculum.manage to the ADMIN role only; instructor access
    // comes solely from trainer ownership.
    $this->seed(AuthoringSeeder::class);
});

/** Create a user with the given web-guard roles (robust to Sanctum's guard switch). */
function localizedRbacUser(string ...$roles): User
{
    $user = User::factory()->create();
    foreach ($roles as $role) {
        $user->assignRole(SpatieRole::findByName($role, 'web'));
    }

    return $user;
}

function localizedSection(?User $trainer = null): Section
{
    $course = Course::factory()->create();
    if ($trainer !== null) {
        $course->syncTrainers([$trainer->id]);
    }

    return Section::factory()->create([
        'course_id' => $course->id,
        'title_i18n' => ['en' => 'Original', 'ar' => 'الأصل'],
    ]);
}

it('denies a student updating localized section content', function () {
    $student = localizedRbacUser('student');
    $section = localizedSection();
    Sanctum::actingAs($student);

    $this->putJson("/api/v1/admin/sections/{$section->public_id}", ['title' => 'Hacked'])
        ->assertForbidden();
});

it('allows the owning instructor to update localized section content', function () {
    $instructor = localizedRbacUser('instructor');
    $section = localizedSection($instructor);
    Sanctum::actingAs($instructor);

    $this->putJson("/api/v1/admin/sections/{$section->public_id}", ['title' => 'Instructor Edit'])
        ->assertSuccessful();
});

it('allows an admin with the global permission to update localized section content', function () {
    $admin = localizedRbacUser('admin');
    $section = localizedSection(); // admin is not a trainer — access relies on the global permission
    Sanctum::actingAs($admin);

    $this->putJson("/api/v1/admin/sections/{$section->public_id}", ['title' => 'Admin Edit'])
        ->assertSuccessful();
});

it('gates the localized model update at the SectionPolicy for each actor', function () {
    $instructor = localizedRbacUser('instructor');
    $student = localizedRbacUser('student');
    $section = localizedSection($instructor);

    expect(Gate::forUser($instructor)->allows('update', $section))->toBeTrue()
        ->and(Gate::forUser($student)->allows('update', $section))->toBeFalse();
});
