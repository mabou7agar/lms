<?php

use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Assessment\Enums\QuestionType;
use App\Domains\Assessment\Models\Assessment;
use App\Domains\Assessment\Models\AssessmentAttempt;
use App\Domains\Assessment\Models\AssessmentQuestion;
use App\Domains\Assessment\Models\QuestionOption;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** A published course + a course-scoped, attemptable one-question assessment. */
function courseScopedAssessment(): array
{
    $course = Course::factory()->create(['status' => CourseStatus::Published]);
    $assessment = Assessment::factory()->published()->create(['course_id' => $course->id]);

    $question = AssessmentQuestion::factory()->worth(1)->create([
        'assessment_id' => $assessment->id,
        'type' => QuestionType::SingleChoice->value,
        'position' => 0,
    ]);
    QuestionOption::factory()->correct()->create(['question_id' => $question->id, 'label' => 'Right', 'position' => 0]);

    return [$course, $assessment->refresh()];
}

it('forbids starting an attempt on a course-scoped assessment when not enrolled', function () {
    [, $assessment] = courseScopedAssessment();
    $stranger = User::factory()->create();

    $this->actingAs($stranger, 'sanctum')
        ->postJson("/api/v1/assessments/{$assessment->public_id}/attempts")
        ->assertForbidden();

    // No attempt row may be created for a non-enrolled user — the questions are never served.
    expect(AssessmentAttempt::count())->toBe(0);
});

it('allows starting an attempt once the learner is enrolled in the course', function () {
    [$course, $assessment] = courseScopedAssessment();
    $learner = User::factory()->create();
    Enrollment::factory()->create(['user_id' => $learner->id, 'course_id' => $course->id]);

    $this->actingAs($learner, 'sanctum')
        ->postJson("/api/v1/assessments/{$assessment->public_id}/attempts")
        ->assertCreated();
});

it('allows starting an attempt when the enrollment is completed (access survives completion)', function () {
    // A learner who finished the course keeps access to take/retake its assessments — the gate is
    // course ACCESS (active OR completed), not strict active enrollment. Regression guard for W07.
    [$course, $assessment] = courseScopedAssessment();
    $learner = User::factory()->create();
    Enrollment::factory()->create([
        'user_id' => $learner->id,
        'course_id' => $course->id,
        'status' => 'completed',
    ]);

    $this->actingAs($learner, 'sanctum')
        ->postJson("/api/v1/assessments/{$assessment->public_id}/attempts")
        ->assertCreated();
});
