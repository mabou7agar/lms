<?php

use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Enums\Role;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

/** An instructor plus a course they train, at the given status. */
function lifecycleApiCourse(CourseStatus $status = CourseStatus::Draft): array
{
    $instructor = User::factory()->create();
    $instructor->assignRole(SpatieRole::findByName(Role::Instructor->value, 'web'));

    $course = Course::factory()->create(['status' => $status]);
    $course->syncTrainers([$instructor->id]);

    Sanctum::actingAs($instructor);

    return [$instructor, $course];
}

it('schedules an owned course through the API', function () {
    [, $course] = lifecycleApiCourse();

    $this->postJson("/api/v1/teach/courses/{$course->public_id}/schedule", [
        'scheduled_publish_at' => now()->addDay()->toIso8601String(),
    ])->assertOk()->assertJsonPath('data.status', 'scheduled');

    expect($course->fresh()->scheduled_publish_at)->not->toBeNull();
});

it('rejects scheduling in the past', function () {
    [, $course] = lifecycleApiCourse();

    $this->postJson("/api/v1/teach/courses/{$course->public_id}/schedule", [
        'scheduled_publish_at' => now()->subDay()->toIso8601String(),
    ])->assertStatus(422);

    expect($course->fresh()->status)->toBe(CourseStatus::Draft);
});

it('restores an archived owned course to draft through the API', function () {
    [, $course] = lifecycleApiCourse(CourseStatus::Archived);

    $this->postJson("/api/v1/teach/courses/{$course->public_id}/restore")
        ->assertOk()->assertJsonPath('data.status', 'draft');
});

it('rejects an illegal transition through the API with 422', function () {
    // Unpublishing a draft is not a legal move (only a Published course may be unpublished).
    [, $course] = lifecycleApiCourse(CourseStatus::Draft);

    $this->postJson("/api/v1/teach/courses/{$course->public_id}/unpublish")->assertStatus(422);

    expect($course->fresh()->status)->toBe(CourseStatus::Draft);
});
