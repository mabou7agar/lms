<?php

use App\Contexts\Learning\Actions\Enrollment\GrantEnrollmentAction;
use App\Contexts\Learning\Enums\EnrollmentSource;
use App\Contexts\Learning\Events\CourseCompleted;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Learning\Contracts\AssignmentRequirementPort;
use App\Platform\Shared\Learning\Contracts\LessonRequiredBlocksPort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);
require_once __DIR__.'/Helpers.php';
require_once __DIR__.'/RuntimeSupport.php';

beforeEach(fn () => bootLearningRuntime());

function enrolledLearner(int $lessonCount = 1): array
{
    [$course, , $lessons] = publishedCourseWithLessons($lessonCount);
    $user = User::factory()->create();
    app(GrantEnrollmentAction::class)->executeByUserId($user->id, $course->id, EnrollmentSource::Free);
    Sanctum::actingAs($user);

    return [$course, $lessons, $user];
}

it('blocks lesson completion while a required assignment is unsatisfied, then allows it', function () {
    [, $lessons, $user] = enrolledLearner();
    $lesson = $lessons->first();

    $fake = new FakeAssignmentRequirementPort;
    $fake->required[$lesson->id] = true;
    $fake->satisfied[$lesson->id] = false;
    app()->instance(AssignmentRequirementPort::class, $fake);

    $this->postJson("/api/v1/lessons/{$lesson->public_id}/complete")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'LEARNING_COMPLETION_BLOCKED')
        ->assertJsonPath('error.details.reasons', ['assignment_required']);

    // Agent C's port now reports the assignment satisfied.
    $fake->satisfied[$lesson->id] = true;

    $this->postJson("/api/v1/lessons/{$lesson->public_id}/complete")
        ->assertOk()->assertJsonPath('data.status', 'completed');
});

it('uses the Null assignment port by default so assignments never block before Agent C is merged', function () {
    [, $lessons] = enrolledLearner();
    $lesson = $lessons->first();

    // No AssignmentRequirementPort override => Null default => completable.
    $this->postJson("/api/v1/lessons/{$lesson->public_id}/complete")
        ->assertOk()->assertJsonPath('data.status', 'completed');
});

it('requires every required block before the lesson completes, then auto-completes on the last one', function () {
    [$course, $lessons] = enrolledLearner();
    $lesson = $lessons->first();

    $blocks = new FakeLessonRequiredBlocksPort;
    $blocks->blocks[$lesson->id] = ['b1', 'b2'];
    app()->instance(LessonRequiredBlocksPort::class, $blocks);

    $this->postJson("/api/v1/lessons/{$lesson->public_id}/complete")
        ->assertStatus(422)->assertJsonPath('error.details.reasons', ['blocks_incomplete']);

    $this->postJson("/api/v1/lessons/{$lesson->public_id}/blocks/b1/complete")->assertOk();
    // Still incomplete: b2 outstanding.
    $byId = collect($this->getJson("/api/v1/courses/{$course->public_id}/curriculum")->json('data.sections.0.lessons'))->keyBy('id');
    expect($byId[$lesson->public_id]['completed'])->toBeFalse();

    // Completing the last required block auto-completes the lesson.
    $this->postJson("/api/v1/lessons/{$lesson->public_id}/blocks/b2/complete")->assertOk();
    $byId = collect($this->getJson("/api/v1/courses/{$course->public_id}/curriculum")->json('data.sections.0.lessons'))->keyBy('id');
    expect($byId[$lesson->public_id]['completed'])->toBeTrue();
});

it('recalculates course completion and emits CourseCompleted through the runtime path', function () {
    Event::fake([CourseCompleted::class]);
    [, $lessons] = enrolledLearner(1);
    $lesson = $lessons->first();

    $res = $this->postJson("/api/v1/lessons/{$lesson->public_id}/complete")->assertOk();
    expect($res->json('data.course_progress_percentage'))->toBe(100);
    Event::assertDispatched(CourseCompleted::class);
});

it('is idempotent when completing the same lesson twice', function () {
    [, $lessons] = enrolledLearner(2);
    $lesson = $lessons->first();

    $this->postJson("/api/v1/lessons/{$lesson->public_id}/complete")->assertOk();
    $second = $this->postJson("/api/v1/lessons/{$lesson->public_id}/complete")->assertOk();

    expect($second->json('data.course_progress_percentage'))->toBe(50); // 1 of 2, unchanged
});

it('marks a lesson viewed without ever regressing a completed lesson', function () {
    [, $lessons] = enrolledLearner(1);
    $lesson = $lessons->first();

    $this->postJson("/api/v1/lessons/{$lesson->public_id}/complete")->assertOk();
    // A late "viewed" beat must not knock a completed lesson back to in_progress.
    $this->postJson("/api/v1/lessons/{$lesson->public_id}/viewed")
        ->assertOk()->assertJsonPath('data.status', 'completed');
});

it('isolates progress between two learners in the same course', function () {
    [$course, $lessons] = enrolledLearner(2);
    $lesson = $lessons->first();
    $this->postJson("/api/v1/lessons/{$lesson->public_id}/complete")->assertOk();

    // A second learner in the same course sees none of the first learner's progress.
    $other = User::factory()->create();
    app(GrantEnrollmentAction::class)->executeByUserId($other->id, $course->id, EnrollmentSource::Free);
    Sanctum::actingAs($other);

    $summary = $this->getJson("/api/v1/courses/{$course->public_id}/progress-summary")->assertOk();
    expect($summary->json('data.completed_lessons'))->toBe(0)
        ->and($summary->json('data.progress_percentage'))->toBe(0);
});
