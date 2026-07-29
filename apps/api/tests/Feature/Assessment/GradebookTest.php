<?php

use App\Domains\Assessment\Models\Assessment;
use App\Domains\Assessment\Models\AssessmentAttempt;
use App\Domains\Assessment\Models\Assignment;
use App\Domains\Assessment\Models\AssignmentSubmission;
use App\Domains\Assessment\Models\SubmissionGrade;
use App\Domains\Assessment\Policies\GradebookPolicy;
use App\Domains\Assessment\Services\GradebookService;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Learning\Contracts\CourseEnrollmentPort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

function asgGradebookWith(array $rosterIds): GradebookService
{
    $port = new class($rosterIds) implements CourseEnrollmentPort
    {
        public function __construct(private array $ids) {}

        public function isEnrolled(int $courseId, int $userId): bool
        {
            return in_array($userId, $this->ids, true);
        }

        public function enrolledLearnerIds(int $courseId): array
        {
            return $this->ids;
        }
    };

    return new GradebookService($port);
}

it('aggregates assignments and quizzes into rows, marking missing cells', function () {
    $courseId = Course::factory()->create()->id;
    $learnerOne = User::factory()->create();
    $learnerTwo = User::factory()->create();
    $assignment = Assignment::factory()->published()->create(['course_id' => $courseId, 'max_grade' => 100]);
    $quiz = Assessment::factory()->published()->create(['course_id' => $courseId]);

    // Learner 1 has a released assignment grade and a graded quiz attempt.
    $sub = AssignmentSubmission::factory()->graded()->create(['assignment_id' => $assignment->id, 'user_id' => $learnerOne->id]);
    SubmissionGrade::factory()->released()->create(['submission_id' => $sub->id, 'score' => 90, 'passed' => true]);
    AssessmentAttempt::factory()->graded()->create([
        'assessment_id' => $quiz->id, 'user_id' => $learnerOne->id, 'score' => 8, 'max_score' => 10, 'percentage' => 80, 'passed' => true,
    ]);

    $page = asgGradebookWith([$learnerOne->id, $learnerTwo->id])->page($courseId, [], 25, 1);
    $rows = collect($page->items())->keyBy('user_id');

    expect($rows->has($learnerOne->id))->toBeTrue()->and($rows->has($learnerTwo->id))->toBeTrue();

    $l1 = $rows->get($learnerOne->id);
    expect($l1['cells'][0]['percent'])->toBe(90.0)      // assignment 90/100
        ->and($l1['cells'][1]['percent'])->toBe(80.0);  // quiz 80%

    // Learner 2 submitted nothing -> both cells missing.
    $l2 = $rows->get($learnerTwo->id);
    expect($l2['cells'][0]['missing'])->toBeTrue()
        ->and($l2['summary']['missing_count'])->toBe(2);
});

it('paginates gradebook learners', function () {
    $courseId = 5000;
    Assignment::factory()->published()->create(['course_id' => $courseId]);

    $page = asgGradebookWith(range(1, 30))->page($courseId, [], 10, 2);

    expect($page->total())->toBe(30)
        ->and($page->perPage())->toBe(10)
        ->and($page->currentPage())->toBe(2)
        ->and(count($page->items()))->toBe(10);
});

it('exports the gradebook as CSV', function () {
    $courseId = 6000;
    $assignment = Assignment::factory()->published()->create(['course_id' => $courseId, 'title' => 'Essay']);
    $sub = AssignmentSubmission::factory()->graded()->create(['assignment_id' => $assignment->id, 'user_id' => 1]);
    SubmissionGrade::factory()->released()->create(['submission_id' => $sub->id, 'score' => 88]);

    $csv = asgGradebookWith([1])->toCsv($courseId);

    expect($csv)->toContain('learner_id')
        ->and($csv)->toContain('assignment:Essay')
        ->and($csv)->toContain('88');
});

it('authorizes gradebook access only for an instructor of the course', function () {
    $this->seed(IdentitySeeder::class);
    $course = Course::factory()->create(['status' => CourseStatus::Draft]);

    $instructor = User::factory()->create();
    $instructor->assignRole(SpatieRole::findByName('instructor', 'web'));
    $course->syncTrainers([$instructor->id]);

    $stranger = User::factory()->create();
    $stranger->assignRole(SpatieRole::findByName('instructor', 'web'));

    $policy = new GradebookPolicy;
    expect($policy->viewForCourse($instructor, $course->id))->toBeTrue()
        ->and($policy->viewForCourse($stranger, $course->id))->toBeFalse();
});
