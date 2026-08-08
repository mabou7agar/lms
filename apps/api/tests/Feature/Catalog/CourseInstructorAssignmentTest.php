<?php

use App\Domains\Catalog\Enums\CatalogPermission;
use App\Domains\Catalog\Exceptions\InstructorAssignmentDeniedException;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Services\CourseInstructorService;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Permission::findOrCreate(CatalogPermission::ManageCourses->value, 'web');
});

function courseManager(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(CatalogPermission::ManageCourses->value);

    return $user;
}

function instructorUser(): User
{
    $user = User::factory()->create();
    $user->assignRole('instructor');

    return $user;
}

function assignments(): CourseInstructorService
{
    return app(CourseInstructorService::class);
}

it('assigns an instructor with role, position and primary flag', function () {
    $manager = courseManager();
    $course = Course::factory()->create();
    $instructor = instructorUser();

    assignments()->assign($course, $instructor->id, $manager, role: 'Lead', isPrimary: true);

    $row = DB::table('course_trainer')
        ->where('course_id', $course->id)->where('user_id', $instructor->id)->first();

    expect($row->role)->toBe('Lead')
        ->and((int) $row->position)->toBe(1)
        ->and((bool) $row->is_primary)->toBeTrue();
});

it('keeps the pivot unique on (course, instructor) when re-assigned', function () {
    $manager = courseManager();
    $course = Course::factory()->create();
    $instructor = instructorUser();

    assignments()->assign($course, $instructor->id, $manager, role: 'TA');
    assignments()->assign($course, $instructor->id, $manager, role: 'Lead');

    $rows = DB::table('course_trainer')
        ->where('course_id', $course->id)->where('user_id', $instructor->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->role)->toBe('Lead');
});

it('enforces at most one primary instructor per course', function () {
    $manager = courseManager();
    $course = Course::factory()->create();
    $a = instructorUser();
    $b = instructorUser();

    assignments()->assign($course, $a->id, $manager, isPrimary: true);
    assignments()->assign($course, $b->id, $manager, isPrimary: true);

    $primaries = DB::table('course_trainer')
        ->where('course_id', $course->id)->where('is_primary', true)->pluck('user_id');

    expect($primaries)->toHaveCount(1)
        ->and((int) $primaries->first())->toBe($b->id);
});

it('promotes a new primary and demotes the previous one via setPrimary', function () {
    $manager = courseManager();
    $course = Course::factory()->create();
    $a = instructorUser();
    $b = instructorUser();

    assignments()->assign($course, $a->id, $manager, isPrimary: true);
    assignments()->assign($course, $b->id, $manager);
    assignments()->setPrimary($course, $b->id, $manager);

    $isPrimary = fn (int $id): bool => (bool) DB::table('course_trainer')
        ->where('course_id', $course->id)->where('user_id', $id)->value('is_primary');

    expect($isPrimary($b->id))->toBeTrue()
        ->and($isPrimary($a->id))->toBeFalse();
});

it('rejects an instructor self-attaching to a course they do not train', function () {
    $instructor = instructorUser();
    $course = Course::factory()->create();

    expect(fn () => assignments()->assign($course, $instructor->id, $instructor))
        ->toThrow(InstructorAssignmentDeniedException::class);

    expect($course->isTrainedBy($instructor->id))->toBeFalse();
});

it('lets an existing trainer add a co-instructor to their course', function () {
    $trainer = instructorUser();
    $course = Course::factory()->create();
    $course->syncTrainers([$trainer->id]);

    $coInstructor = instructorUser();
    assignments()->assign($course, $coInstructor->id, $trainer, role: 'Co-instructor');

    expect($course->isTrainedBy($coInstructor->id))->toBeTrue();
});

it('adds and removes instructors', function () {
    $manager = courseManager();
    $course = Course::factory()->create();
    $instructor = instructorUser();

    assignments()->assign($course, $instructor->id, $manager);
    expect($course->isTrainedBy($instructor->id))->toBeTrue();

    assignments()->unassign($course, $instructor->id, $manager);
    expect($course->isTrainedBy($instructor->id))->toBeFalse();
});

it('reorders instructors to match the supplied order', function () {
    $manager = courseManager();
    $course = Course::factory()->create();
    $a = instructorUser();
    $b = instructorUser();
    $c = instructorUser();

    assignments()->assign($course, $a->id, $manager);
    assignments()->assign($course, $b->id, $manager);
    assignments()->assign($course, $c->id, $manager);

    assignments()->reorder($course, [$c->id, $a->id, $b->id], $manager);

    $positions = DB::table('course_trainer')
        ->where('course_id', $course->id)->pluck('position', 'user_id');

    expect((int) $positions[$c->id])->toBe(1)
        ->and((int) $positions[$a->id])->toBe(2)
        ->and((int) $positions[$b->id])->toBe(3);
});
