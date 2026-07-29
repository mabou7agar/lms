<?php

use App\Domains\Assessment\Enums\AssignmentState;
use App\Domains\Assessment\Events\AssignmentPublished;
use App\Domains\Assessment\Models\Assignment;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Contracts\CourseAccessPort;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IdentitySeeder::class);

    // Mirror the gate the integrator registers in AssessmentServiceProvider.
    Gate::define('assignment.manage-assignment', function (User $user, Assignment $assignment): bool {
        return app(CourseAccessPort::class)->canManageContent($user, (int) $assignment->course_id);
    });
});

function asgInstructorFor(Course $course): User
{
    $user = User::factory()->create();
    $user->assignRole(SpatieRole::findByName('instructor', 'web'));
    $course->syncTrainers([$user->id]);

    return $user;
}

function asgDraftCourse(): Course
{
    return Course::factory()->create(['status' => CourseStatus::Draft]);
}

it('lets an assigned instructor create an assignment on their own course', function () {
    $course = asgDraftCourse();
    $instructor = asgInstructorFor($course);

    $this->actingAs($instructor, 'sanctum')
        ->postJson("/api/v1/admin/courses/{$course->public_id}/assignments", [
            'title' => 'Essay 1',
            'submission_type' => 'text',
        ])
        ->assertCreated()
        ->assertJsonPath('data.publish_state', AssignmentState::Draft->value);

    expect(Assignment::where('course_id', $course->id)->count())->toBe(1);
});

it('hides a course the instructor does not train behind a 404', function () {
    $course = asgDraftCourse();
    $stranger = User::factory()->create();
    $stranger->assignRole(SpatieRole::findByName('instructor', 'web'));

    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/v1/admin/courses/{$course->public_id}/assignments", ['title' => 'X', 'submission_type' => 'text'])
        ->assertNotFound();
});

it('builds a rubric with deterministic totals and publishes an assignment', function () {
    Event::fake([AssignmentPublished::class]);
    $course = asgDraftCourse();
    $instructor = asgInstructorFor($course);
    $assignment = Assignment::factory()->create(['course_id' => $course->id]);

    $this->actingAs($instructor, 'sanctum')
        ->putJson("/api/v1/admin/assignments/{$assignment->public_id}/rubric", [
            'title' => 'R',
            'criteria' => [
                ['title' => 'A', 'levels' => [['title' => 'lo', 'points' => 1], ['title' => 'hi', 'points' => 5]]],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.total_points', 5.0);

    $this->actingAs($instructor, 'sanctum')
        ->postJson("/api/v1/admin/assignments/{$assignment->public_id}/publish")
        ->assertOk()
        ->assertJsonPath('data.publish_state', AssignmentState::Published->value);

    Event::assertDispatched(AssignmentPublished::class);
});
