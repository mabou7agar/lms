<?php

use App\Contexts\Learning\Actions\Enrollment\GrantEnrollmentAction;
use App\Contexts\Learning\Enums\EnrollmentSource;
use App\Contexts\Learning\Enums\EnrollmentStatus;
use App\Contexts\Learning\Events\CourseCompleted;
use App\Contexts\Learning\Models\CourseCompletionPolicy;
use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Models\LessonVideoProgress;
use App\Contexts\Learning\Services\CourseCompletionEvaluator;
use App\Domains\Assessment\Models\Assessment;
use App\Domains\Assessment\Models\AssessmentAttempt;
use App\Domains\Certification\Models\Certificate;
use App\Platform\Identity\Models\User;
use App\Platform\Shared\Learning\Contracts\AssignmentRequirementPort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);
require_once __DIR__.'/Helpers.php';
require_once __DIR__.'/RuntimeSupport.php';

/**
 * @return array{0: \App\Domains\Catalog\Models\Course, 1: \Illuminate\Support\Collection<int, \App\Domains\Authoring\Models\Lesson>, 2: User, 3: Enrollment}
 */
function enrollForPolicy(int $lessonCount = 1): array
{
    [$course, , $lessons] = publishedCourseWithLessons($lessonCount);
    $user = User::factory()->create();
    app(GrantEnrollmentAction::class)->executeByUserId($user->id, $course->id, EnrollmentSource::Free);
    Sanctum::actingAs($user);

    $enrollment = Enrollment::query()->where('user_id', $user->id)->where('course_id', $course->id)->firstOrFail();

    return [$course, $lessons, $user, $enrollment];
}

function completeLesson(string $lessonPublicId): \Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/v1/lessons/{$lessonPublicId}/progress", ['status' => 'completed']);
}

it('REGRESSION: a course with no policy row still completes at 100% lessons and emits CourseCompleted', function () {
    [, $lessons] = enrollForPolicy(1);
    Event::fake([CourseCompleted::class]);

    $res = completeLesson($lessons->first()->public_id)->assertOk();

    expect($res->json('data.course_progress_percentage'))->toBe(100);
    Event::assertDispatched(CourseCompleted::class);
});

it('keeps the enrollment in-progress until a required quiz is passed, then completes', function () {
    [$course, $lessons, $user, $enrollment] = enrollForPolicy(1);
    CourseCompletionPolicy::create(['course_id' => $course->id, 'require_required_quizzes' => true]);

    $quiz = Assessment::factory()->published()->create([
        'course_id' => $course->id, 'required_for_completion' => true, 'passing_score' => 60,
    ]);

    Event::fake([CourseCompleted::class]);

    // All lessons done, but the required quiz is unpassed: 100% progress, still ACTIVE.
    $res = completeLesson($lessons->first()->public_id)->assertOk();
    expect($res->json('data.course_progress_percentage'))->toBe(100);
    expect($enrollment->refresh()->status)->toBe(EnrollmentStatus::Active);
    Event::assertNotDispatched(CourseCompleted::class);

    // Learner passes the quiz; re-recording progress re-evaluates and now completes.
    AssessmentAttempt::factory()->graded()->create([
        'assessment_id' => $quiz->id, 'user_id' => $user->id, 'passed' => true,
    ]);

    completeLesson($lessons->first()->public_id)->assertOk();
    expect($enrollment->refresh()->status)->toBe(EnrollmentStatus::Completed);
    Event::assertDispatched(CourseCompleted::class);
});

it('require_final_exam gates on the specific exam, not any passed assessment', function () {
    [$course, $lessons, $user, $enrollment] = enrollForPolicy(1);
    $exam = Assessment::factory()->published()->create(['course_id' => $course->id, 'passing_score' => 50]);
    $other = Assessment::factory()->published()->create(['course_id' => $course->id, 'passing_score' => 50]);

    CourseCompletionPolicy::create([
        'course_id' => $course->id,
        'require_final_exam' => true,
        'final_exam_assessment_id' => $exam->id,
    ]);

    // Passing a DIFFERENT assessment must not satisfy the final-exam gate.
    AssessmentAttempt::factory()->graded()->create(['assessment_id' => $other->id, 'user_id' => $user->id, 'passed' => true]);
    completeLesson($lessons->first()->public_id)->assertOk();
    expect($enrollment->refresh()->status)->toBe(EnrollmentStatus::Active);

    // Passing the named exam completes it.
    AssessmentAttempt::factory()->graded()->create(['assessment_id' => $exam->id, 'user_id' => $user->id, 'passed' => true]);
    completeLesson($lessons->first()->public_id)->assertOk();
    expect($enrollment->refresh()->status)->toBe(EnrollmentStatus::Completed);
});

it('min_watch_percentage gates until the watched threshold is met', function () {
    [$course, $lessons, $user, $enrollment] = enrollForPolicy(1);
    CourseCompletionPolicy::create(['course_id' => $course->id, 'min_watch_percentage' => 80]);

    $lesson = $lessons->first();

    // Watched 50% of a known 100s video — below the 80% gate.
    $video = LessonVideoProgress::create([
        'enrollment_id' => $enrollment->id, 'user_id' => $user->id, 'lesson_id' => $lesson->id,
        'position_seconds' => 50, 'watched_seconds' => 50, 'duration_seconds' => 100, 'completed' => false,
    ]);

    completeLesson($lesson->public_id)->assertOk();
    expect($enrollment->refresh()->status)->toBe(EnrollmentStatus::Active);

    // Cross the threshold, then re-evaluate.
    $video->forceFill(['watched_seconds' => 90])->save();
    completeLesson($lesson->public_id)->assertOk();
    expect($enrollment->refresh()->status)->toBe(EnrollmentStatus::Completed);
});

it('require_required_assignments reuses the assignment requirement port at course scope', function () {
    [$course, $lessons, $user, $enrollment] = enrollForPolicy(2);
    // Isolate the assignment gate from the lesson rule so the entanglement with the lesson-level
    // assignment gate does not mask the course-level decision under test.
    CourseCompletionPolicy::create([
        'course_id' => $course->id,
        'require_all_lessons' => false,
        'require_required_assignments' => true,
    ]);

    $gatedLesson = $lessons->last();

    $fake = new FakeAssignmentRequirementPort;
    $fake->required[$gatedLesson->id] = true;
    $fake->satisfied[$gatedLesson->id] = false;
    app()->instance(AssignmentRequirementPort::class, $fake);

    $evaluator = app(CourseCompletionEvaluator::class);
    expect($evaluator->isComplete($enrollment))->toBeFalse();

    $fake->satisfied[$gatedLesson->id] = true;
    expect($evaluator->isComplete($enrollment))->toBeTrue();
});

it('issues a certificate only once the policy is satisfied, not before', function () {
    [$course, $lessons, $user] = enrollForPolicy(1);
    CourseCompletionPolicy::create(['course_id' => $course->id, 'require_required_quizzes' => true]);
    $quiz = Assessment::factory()->published()->create([
        'course_id' => $course->id, 'required_for_completion' => true, 'passing_score' => 60,
    ]);

    // Lessons done but quiz unpassed: no completion event, hence no certificate.
    completeLesson($lessons->first()->public_id)->assertOk();
    expect(Certificate::query()->where('course_id', $course->id)->where('user_id', $user->id)->count())->toBe(0);

    // Pass the quiz and re-evaluate: CourseCompleted now fires and the certificate is minted.
    AssessmentAttempt::factory()->graded()->create(['assessment_id' => $quiz->id, 'user_id' => $user->id, 'passed' => true]);
    completeLesson($lessons->first()->public_id)->assertOk();
    expect(Certificate::query()->where('course_id', $course->id)->where('user_id', $user->id)->count())->toBe(1);
});

it('scopes required quizzes to the course: another course\'s required quiz never blocks this one', function () {
    [$courseA, $lessonsA] = enrollForPolicy(1);
    CourseCompletionPolicy::create(['course_id' => $courseA->id, 'require_required_quizzes' => true]);

    // A required, unpassed quiz belonging to a DIFFERENT course must not leak into course A's decision.
    [$courseB] = publishedCourseWithLessons(1);
    Assessment::factory()->published()->create([
        'course_id' => $courseB->id, 'required_for_completion' => true, 'passing_score' => 60,
    ]);

    Event::fake([CourseCompleted::class]);
    completeLesson($lessonsA->first()->public_id)->assertOk();

    // Course A has no required quizzes of its own, so it completes normally.
    Event::assertDispatched(CourseCompleted::class);
});
