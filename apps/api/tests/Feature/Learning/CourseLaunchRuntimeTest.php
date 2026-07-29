<?php

use App\Contexts\Learning\Actions\Enrollment\GrantEnrollmentAction;
use App\Contexts\Learning\Enums\EnrollmentSource;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);
require_once __DIR__.'/Helpers.php';
require_once __DIR__.'/RuntimeSupport.php';

beforeEach(fn () => bootLearningRuntime());

it('launches the course shell for an enrolled learner', function () {
    [$course, , $lessons] = publishedCourseWithLessons(3);
    $user = User::factory()->create();
    app(GrantEnrollmentAction::class)->executeByUserId($user->id, $course->id, EnrollmentSource::Free);
    Sanctum::actingAs($user);

    $res = $this->postJson("/api/v1/courses/{$course->public_id}/launch")->assertOk();

    expect($res->json('data.course.id'))->toBe($course->public_id)
        ->and($res->json('data.progress.total_lessons'))->toBe(3)
        ->and($res->json('data.progress.completed_lessons'))->toBe(0)
        ->and($res->json('data.resume.lesson_id'))->toBe($lessons->first()->public_id);
});

it('rejects a launch for a learner who is not enrolled', function () {
    [$course] = publishedCourseWithLessons(1);
    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/courses/{$course->public_id}/launch")
        ->assertStatus(403)->assertJsonPath('error.code', 'LEARNING_NOT_ENROLLED');
});

it('hides an unpublished course as 404 even to an enrolled learner', function () {
    $course = Course::factory()->create(); // draft (not published)
    $user = User::factory()->create();
    app(GrantEnrollmentAction::class)->executeByUserId($user->id, $course->id, EnrollmentSource::Free);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/courses/{$course->public_id}/launch")->assertStatus(404);
});

it('returns a per-course progress summary and resume pointer', function () {
    [$course, , $lessons] = publishedCourseWithLessons(2);
    $user = User::factory()->create();
    app(GrantEnrollmentAction::class)->executeByUserId($user->id, $course->id, EnrollmentSource::Free);
    Sanctum::actingAs($user);

    $this->postJson("/api/v1/lessons/{$lessons->first()->public_id}/progress", ['status' => 'completed'])->assertOk();

    $summary = $this->getJson("/api/v1/courses/{$course->public_id}/progress-summary")->assertOk();
    expect($summary->json('data.completed_lessons'))->toBe(1)
        ->and($summary->json('data.total_lessons'))->toBe(2)
        ->and($summary->json('data.progress_percentage'))->toBe(50);

    $this->getJson("/api/v1/courses/{$course->public_id}/resume")->assertOk()
        ->assertJsonPath('data.resume_lesson_id', $lessons->get(1)->public_id);
});
