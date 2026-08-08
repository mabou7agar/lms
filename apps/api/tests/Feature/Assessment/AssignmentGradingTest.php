<?php

use App\Domains\Assessment\Enums\SubmissionStatus;
use App\Domains\Assessment\Events\AssignmentGradeReleased;
use App\Domains\Assessment\Exceptions\GradeConflictException;
use App\Domains\Assessment\Http\Resources\LearnerSubmissionResource;
use App\Domains\Assessment\Http\Resources\SubmissionResource;
use App\Domains\Assessment\Models\Assignment;
use App\Domains\Assessment\Models\AssignmentSubmission;
use App\Domains\Assessment\Models\SubmissionGrade;
use App\Domains\Assessment\Models\SubmissionGradeEvent;
use App\Domains\Assessment\Services\GradingService;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

function asgGrading(): GradingService
{
    return new GradingService(app(AuditLogger::class));
}

function asgSubmittedFor(Assignment $assignment, ?int $userId = null): AssignmentSubmission
{
    // A real learner row: releasing a grade / requesting changes now notifies the learner, and the
    // notifications table FKs user_id -> users.
    $userId ??= User::factory()->create()->id;

    return AssignmentSubmission::factory()->submitted()->create([
        'assignment_id' => $assignment->id, 'user_id' => $userId,
    ]);
}

it('records a numeric grade and derives pass/fail from the pass mark', function () {
    $assignment = Assignment::factory()->create(['passing_grade' => 50, 'max_grade' => 100]);
    $submission = asgSubmittedFor($assignment);

    $grade = asgGrading()->grade($submission->load('assignment'), 9, ['score' => 75]);

    expect((float) $grade->score)->toBe(75.0)
        ->and($grade->passed)->toBeTrue()
        ->and($grade->version)->toBe(1)
        ->and($submission->fresh()->status)->toBe(SubmissionStatus::UnderReview);
});

it('detects a concurrent grading conflict via the version', function () {
    $assignment = Assignment::factory()->create(['passing_grade' => 50]);
    $submission = asgSubmittedFor($assignment)->load('assignment');

    asgGrading()->grade($submission, 9, ['score' => 60]);   // version -> 1

    // A second grader who loaded version 0 tries to write.
    expect(fn () => asgGrading()->grade($submission->fresh('assignment'), 10, ['score' => 90, 'expected_version' => 0]))
        ->toThrow(GradeConflictException::class);
});

it('scores from the rubric selection against the immutable snapshot', function () {
    $assignment = Assignment::factory()->create(['passing_grade' => 5, 'max_grade' => 8]);
    $submission = asgSubmittedFor($assignment);
    $submission->forceFill(['rubric_snapshot' => [
        'total_points' => 8.0,
        'criteria' => [
            ['public_id' => 'c1', 'levels' => [
                ['public_id' => 'l1a', 'points' => 1.0], ['public_id' => 'l1b', 'points' => 4.0],
            ]],
            ['public_id' => 'c2', 'levels' => [
                ['public_id' => 'l2a', 'points' => 0.0], ['public_id' => 'l2b', 'points' => 3.0],
            ]],
        ],
    ]])->save();

    $grade = asgGrading()->grade($submission->load('assignment'), 9, [
        'rubric_result' => [
            ['criterion_public_id' => 'c1', 'level_public_id' => 'l1b'],
            ['criterion_public_id' => 'c2', 'level_public_id' => 'l2b'],
        ],
    ]);

    // 4 + 3 = 7
    expect((float) $grade->score)->toBe(7.0)->and($grade->passed)->toBeTrue();
});

it('requests changes, allowing the learner to resubmit', function () {
    $assignment = Assignment::factory()->create();
    $submission = asgSubmittedFor($assignment);

    asgGrading()->requestChanges($submission, 9, 'Fix section 2');

    expect($submission->fresh()->status)->toBe(SubmissionStatus::ChangesRequested);
});

it('keeps a grade private until released, then reveals it and fires the completion event', function () {
    Event::fake([AssignmentGradeReleased::class]);
    $assignment = Assignment::factory()->create(['passing_grade' => 50, 'course_id' => 42, 'lesson_id' => 7]);
    $submission = asgSubmittedFor($assignment)->load('assignment');

    asgGrading()->grade($submission, 9, ['score' => 80, 'private_notes' => 'plagiarism check ok']);

    // Before release: learner sees no grade block; instructor sees private notes.
    $learnerView = (new LearnerSubmissionResource($submission->fresh(['files', 'grade'])))->toArray(request());
    expect($learnerView['grade'])->toBeNull();

    asgGrading()->release($submission->fresh('assignment'), 9);

    $learnerAfter = (new LearnerSubmissionResource($submission->fresh(['files', 'grade'])))->toArray(request());
    expect($learnerAfter['grade'])->not->toBeNull()
        ->and($learnerAfter['grade'])->not->toHaveKey('private_notes')
        ->and($submission->fresh()->status)->toBe(SubmissionStatus::Graded);

    $instructorView = (new SubmissionResource($submission->fresh(['files', 'grade'])))->toArray(request());
    expect($instructorView['grade']['private_notes'])->toBe('plagiarism check ok');

    Event::assertDispatched(AssignmentGradeReleased::class);
});

it('appends an immutable grade-history row per action', function () {
    $assignment = Assignment::factory()->create(['passing_grade' => 50]);
    $submission = asgSubmittedFor($assignment)->load('assignment');

    asgGrading()->grade($submission, 9, ['score' => 60]);          // graded
    asgGrading()->grade($submission->fresh('assignment'), 9, ['score' => 70, 'expected_version' => 1]); // regraded
    asgGrading()->release($submission->fresh('assignment'), 9);    // released

    expect(SubmissionGradeEvent::where('submission_id', $submission->id)->count())->toBe(3)
        ->and(SubmissionGrade::where('submission_id', $submission->id)->value('version'))->toBe(2);
});
