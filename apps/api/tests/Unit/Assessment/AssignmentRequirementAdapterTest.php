<?php

use App\Domains\Assessment\Models\Assignment;
use App\Domains\Assessment\Models\AssignmentSubmission;
use App\Domains\Assessment\Models\SubmissionGrade;
use App\Domains\Assessment\Support\AssignmentRequirementAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function asgRequiredAssignment(int $lessonId = 900): Assignment
{
    return Assignment::factory()->published()->requiredForCompletion()->create([
        'lesson_id' => $lessonId,
        'passing_grade' => 50,
        'max_grade' => 100,
    ]);
}

it('reports a lesson has required assignments only when one is published + required', function () {
    $adapter = new AssignmentRequirementAdapter;

    expect($adapter->hasRequired(900))->toBeFalse();

    asgRequiredAssignment(900);

    expect($adapter->hasRequired(900))->toBeTrue()
        ->and($adapter->hasRequired(901))->toBeFalse();
});

it('treats a lesson with no required assignments as vacuously satisfied', function () {
    expect((new AssignmentRequirementAdapter)->requiredSatisfied(902, 7))->toBeTrue();
});

it('only counts a released passing grade as satisfying the requirement', function () {
    $assignment = asgRequiredAssignment(900);
    $adapter = new AssignmentRequirementAdapter;

    $submission = AssignmentSubmission::factory()->graded()->create([
        'assignment_id' => $assignment->id, 'user_id' => 7,
    ]);

    // Graded, passing, but NOT released -> not satisfied.
    $grade = SubmissionGrade::factory()->create([
        'submission_id' => $submission->id, 'score' => 80, 'passed' => true, 'released_at' => null,
    ]);
    expect($adapter->requiredSatisfied(900, 7))->toBeFalse();

    // Released + passing -> satisfied.
    $grade->forceFill(['released_at' => now()])->save();
    expect($adapter->requiredSatisfied(900, 7))->toBeTrue();

    // Released but failing -> not satisfied.
    $grade->forceFill(['passed' => false])->save();
    expect($adapter->requiredSatisfied(900, 7))->toBeFalse();
});
