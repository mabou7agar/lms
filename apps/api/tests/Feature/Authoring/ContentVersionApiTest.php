<?php

use App\Domains\Authoring\Enums\AuthoringPermission;
use App\Domains\Authoring\Services\ContentVersioningService;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function versionTrainer(Course $course, bool $strong): User
{
    $user = User::factory()->create();
    DB::table('course_trainer')->insert(['course_id' => $course->id, 'user_id' => $user->id]);

    if ($strong) {
        Permission::findOrCreate(AuthoringPermission::ManageCurriculum->value, 'web');
        $user->givePermissionTo(AuthoringPermission::ManageCurriculum->value);
    }

    return $user->fresh();
}

it('requires authentication', function () {
    $course = courseWithLessons(1);

    $this->getJson("/api/v1/admin/courses/{$course->public_id}/versions")->assertUnauthorized();
});

it('runs the full version lifecycle for a super admin', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');
    Sanctum::actingAs($admin);

    $course = courseWithLessons(2);
    $destination = Course::factory()->create();

    // create snapshots (force to get distinct versions) + history newest-first
    $this->postJson("/api/v1/admin/courses/{$course->public_id}/versions", ['label' => 'one'])->assertCreated();
    $this->postJson("/api/v1/admin/courses/{$course->public_id}/versions", ['force' => true])->assertCreated();
    $v3 = $this->postJson("/api/v1/admin/courses/{$course->public_id}/versions", ['force' => true])
        ->assertCreated()->json('data');

    $history = $this->getJson("/api/v1/admin/courses/{$course->public_id}/versions")->assertOk();
    expect($history->json('data.0.version_number'))->toBe(3)
        ->and($history->json('meta.total'))->toBe(3);

    // show
    $this->getJson("/api/v1/admin/versions/{$v3['id']}")->assertOk()
        ->assertJsonPath('data.version_number', 3);

    // clone
    $this->postJson("/api/v1/admin/versions/{$v3['id']}/clone", ['label' => 'c'])
        ->assertCreated()->assertJsonPath('data.reason', 'clone');

    // rollback
    $this->postJson("/api/v1/admin/versions/{$v3['id']}/rollback")
        ->assertCreated()->assertJsonPath('data.reason', 'rollback');

    // restore (returns a safety snapshot)
    $this->postJson("/api/v1/admin/versions/{$v3['id']}/restore")
        ->assertOk()->assertJsonPath('data.safety_snapshot.reason', 'safety');

    // fork into the destination course
    $this->postJson("/api/v1/admin/versions/{$v3['id']}/fork", ['destination_course_id' => $destination->public_id])
        ->assertCreated()->assertJsonPath('data.reason', 'fork');
});

it('404s course-scoped access for a non-owner and 403s a version they do not own', function () {
    $admin = User::factory()->create();
    $admin->assignRole('super_admin');

    $course = courseWithLessons(1);
    $version = app(ContentVersioningService::class)->createSnapshot((int) $course->id, (int) $admin->id, null, false);

    $stranger = User::factory()->create();
    Sanctum::actingAs($stranger);

    $this->getJson("/api/v1/admin/courses/{$course->public_id}/versions")->assertNotFound();
    $this->postJson("/api/v1/admin/courses/{$course->public_id}/versions")->assertNotFound();
    $this->getJson("/api/v1/admin/versions/{$version->public_id}")->assertForbidden();
});

it('lets a course-owning trainer view and create and clone, but not restore or rollback', function () {
    $course = courseWithLessons(1);
    $owner = versionTrainer($course, strong: false);
    Sanctum::actingAs($owner);

    $created = $this->postJson("/api/v1/admin/courses/{$course->public_id}/versions")->assertCreated()->json('data');
    $this->getJson("/api/v1/admin/courses/{$course->public_id}/versions")->assertOk();
    $this->postJson("/api/v1/admin/versions/{$created['id']}/clone")->assertCreated();

    $this->postJson("/api/v1/admin/versions/{$created['id']}/restore")->assertForbidden();
    $this->postJson("/api/v1/admin/versions/{$created['id']}/rollback")->assertForbidden();
});

it('lets a trainer with the manage-curriculum permission restore and rollback', function () {
    $course = courseWithLessons(1);
    $owner = versionTrainer($course, strong: true);
    Sanctum::actingAs($owner);

    $created = $this->postJson("/api/v1/admin/courses/{$course->public_id}/versions")->assertCreated()->json('data');

    $this->postJson("/api/v1/admin/versions/{$created['id']}/restore")->assertOk();
    $this->postJson("/api/v1/admin/versions/{$created['id']}/rollback")->assertCreated();
});
