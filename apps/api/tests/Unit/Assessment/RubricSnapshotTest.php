<?php

use App\Domains\Assessment\Models\Assignment;
use App\Domains\Assessment\Models\AssignmentSubmission;
use App\Domains\Assessment\Services\AssignmentService;
use App\Domains\Assessment\Support\RubricSnapshot;
use App\Platform\Shared\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function asgRubricPayload(): array
{
    return [
        'title' => 'Essay rubric',
        'criteria' => [
            ['title' => 'Argument', 'levels' => [
                ['title' => 'Weak', 'points' => 0],
                ['title' => 'Strong', 'points' => 4],
            ]],
            ['title' => 'Grammar', 'levels' => [
                ['title' => 'Poor', 'points' => 1],
                ['title' => 'Clean', 'points' => 3],
            ]],
        ],
    ];
}

it('computes deterministic rubric totals from the highest level per criterion', function () {
    $assignment = Assignment::factory()->create();
    $service = new AssignmentService(app(AuditLogger::class));

    $rubric = $service->buildRubric($assignment, asgRubricPayload());

    // max(0,4) + max(1,3) = 4 + 3 = 7
    expect((float) $rubric->total_points)->toBe(7.0)
        ->and((float) $rubric->criteria[0]->max_points)->toBe(4.0)
        ->and((float) $rubric->criteria[1]->max_points)->toBe(3.0);
});

it('freezes the rubric snapshot so later rubric edits never rewrite historical grading', function () {
    $assignment = Assignment::factory()->create();
    $service = new AssignmentService(app(AuditLogger::class));
    $rubric = $service->buildRubric($assignment, asgRubricPayload());

    // Simulate a submitted attempt carrying the snapshot.
    $submission = AssignmentSubmission::factory()->submitted()->create([
        'assignment_id' => $assignment->id,
        'user_id' => 501,
        'rubric_snapshot' => RubricSnapshot::forRubric($assignment->fresh()->rubric()),
    ]);

    expect($submission->rubric_snapshot['total_points'])->toBe(7.0);

    // Author changes the live rubric afterwards.
    $service->buildRubric($assignment->fresh(), [
        'title' => 'Revised',
        'criteria' => [['title' => 'Only', 'levels' => [['title' => 'Max', 'points' => 99]]]],
    ]);

    // The historical snapshot is untouched.
    expect($submission->fresh()->rubric_snapshot['total_points'])->toBe(7.0)
        ->and($assignment->fresh()->rubric()->total_points)->toBe('99.00');
});
