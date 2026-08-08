<?php

use App\Contexts\Learning\Enums\EnrollmentStatus;
use App\Contexts\Learning\Enums\LessonProgressStatus;
use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Models\LearningSession;
use App\Contexts\Learning\Models\LessonProgress;
use App\Contexts\Learning\Models\LessonVideoProgress;
use App\Domains\Assessment\Models\Assessment;
use App\Domains\Assessment\Models\AssessmentAttempt;
use App\Domains\Authoring\Enums\LessonType;
use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Models\Course;
use App\Domains\Certification\Enums\CertificateStatus;
use App\Domains\Certification\Models\Certificate;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IdentitySeeder::class);
});

function drillUser(string $role = 'instructor'): User
{
    $user = User::factory()->create();
    $user->assignRole(SpatieRole::findByName($role, 'web'));

    return $user;
}

/**
 * A published course trained by $owner with $lessonCount published lessons in one section.
 *
 * @return array{0: Course, 1: list<Lesson>}
 */
function drillCourse(User $owner, int $lessonCount = 2): array
{
    $course = Course::factory()->published()->create();
    $course->syncTrainers([$owner->id]);

    $section = Section::factory()->create([
        'course_id' => $course->id,
        'publish_state' => PublishState::Published->value,
        'position' => 1,
    ]);

    $lessons = [];
    for ($i = 1; $i <= $lessonCount; $i++) {
        $lessons[] = Lesson::factory()->published()->create([
            'section_id' => $section->id,
            'position' => $i,
        ]);
    }

    return [$course, $lessons];
}

function drillEnrol(Course $course, User $student, int $progress = 40, EnrollmentStatus $status = EnrollmentStatus::Active): Enrollment
{
    return Enrollment::factory()->create([
        'course_id' => $course->id,
        'user_id' => $student->id,
        'status' => $status->value,
        'progress_percentage' => $progress,
    ]);
}

function drillProgress(Enrollment $enrollment, Lesson $lesson, LessonProgressStatus $status): void
{
    LessonProgress::create([
        'enrollment_id' => $enrollment->id,
        'lesson_id' => $lesson->id,
        'status' => $status->value,
        'position_seconds' => 0,
        'completed_at' => $status === LessonProgressStatus::Completed ? now() : null,
    ]);
}

/** Queries issued by $work and nothing else. */
function drillQueriesFor(callable $work): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $work();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();

    return $count;
}

function drillUrl(Course $course, User $student): string
{
    return "/api/v1/teach/courses/{$course->public_id}/students/{$student->public_id}";
}

// ---------------------------------------------------------------- authorization

it('denies an unauthenticated caller', function () {
    [$course] = drillCourse(drillUser());
    $this->getJson(drillUrl($course, drillUser('student')))->assertUnauthorized();
});

it('denies a non-instructor', function () {
    $me = drillUser('student');
    [$course, $lessons] = drillCourse(drillUser());
    $student = User::factory()->create();
    drillEnrol($course, $student);

    $this->actingAs($me, 'sanctum')->getJson(drillUrl($course, $student))->assertForbidden();
});

it('returns 404 for a course the caller does not train', function () {
    $me = drillUser();
    [$theirs] = drillCourse(drillUser());
    $student = User::factory()->create();
    drillEnrol($theirs, $student);

    $this->actingAs($me, 'sanctum')->getJson(drillUrl($theirs, $student))->assertNotFound();
});

it('returns 404 for a learner not enrolled in the course', function () {
    $me = drillUser();
    [$course] = drillCourse($me);
    $stranger = User::factory()->create();

    $this->actingAs($me, 'sanctum')->getJson(drillUrl($course, $stranger))->assertNotFound();
});

// ---------------------------------------------------------------- payload

it('returns every documented field for an enrolled learner', function () {
    $me = drillUser();
    [$course, $lessons] = drillCourse($me, lessonCount: 2);
    [$l1, $l2] = $lessons;

    $student = User::factory()->create(['name' => 'Learner A']);
    $enrollment = drillEnrol($course, $student, progress: 40);

    drillProgress($enrollment, $l1, LessonProgressStatus::Completed);
    drillProgress($enrollment, $l2, LessonProgressStatus::InProgress);

    LessonVideoProgress::create([
        'enrollment_id' => $enrollment->id, 'user_id' => $student->id, 'lesson_id' => $l1->id,
        'position_seconds' => 120, 'watched_seconds' => 120, 'completed' => false,
    ]);

    LearningSession::create([
        'user_id' => $student->id, 'course_id' => $course->id,
        'last_lesson_id' => $l2->id, 'last_activity_at' => now(),
    ]);

    $data = $this->actingAs($me, 'sanctum')->getJson(drillUrl($course, $student))
        ->assertOk()
        ->json('data');

    // PII-safe: public id + name, never an internal id or email.
    expect($data['student']['name'])->toBe('Learner A')
        ->and($data['student']['id'])->toBe($student->public_id)
        ->and($data)->not->toHaveKey('email');

    // Resume pointer is the first uncompleted lesson, as a public ref.
    expect($data['current_lesson']['id'])->toBe($l2->public_id)
        ->and($data['percent_complete'])->toBe(40)
        ->and($data['watched_seconds'])->toBe(120)
        ->and($data['lessons_completed'])->toBe(1)
        ->and($data['lessons_total'])->toBe(2)
        ->and($data['last_activity_at'])->not->toBeNull()
        ->and($data['started_at'])->not->toBeNull()
        ->and($data)->toHaveKeys(['assessments', 'certificate']);
});

it('summarises required-assessment outcomes for the learner', function () {
    $me = drillUser();
    [$course, $lessons] = drillCourse($me);
    $student = User::factory()->create();
    drillEnrol($course, $student);

    $assessment = Assessment::factory()->create([
        'course_id' => $course->id, 'status' => 'published', 'required_for_completion' => true,
    ]);
    $quiz = Lesson::factory()->create([
        'section_id' => $lessons[0]->section_id, 'type' => LessonType::Quiz->value,
        'publish_state' => PublishState::Published->value, 'assessment_id' => $assessment->id, 'position' => 9,
    ]);
    AssessmentAttempt::factory()->create([
        'assessment_id' => $assessment->id, 'user_id' => $student->id, 'lesson_id' => $quiz->id,
        'status' => 'graded', 'passed' => true, 'submitted_at' => now(),
    ]);

    $data = $this->actingAs($me, 'sanctum')->getJson(drillUrl($course, $student))->assertOk()->json('data');

    expect($data['assessments']['required'])->toBe(1)
        ->and($data['assessments']['passed'])->toBe(1)
        ->and($data['assessments']['all_required_passed'])->toBeTrue();
});

it('reflects an issued certificate in the eligibility flag', function () {
    $me = drillUser();
    [$course] = drillCourse($me);
    $student = User::factory()->create();
    drillEnrol($course, $student, progress: 100, status: EnrollmentStatus::Completed);

    Certificate::factory()->create([
        'course_id' => $course->id, 'user_id' => $student->id, 'status' => CertificateStatus::Issued->value,
    ]);

    $data = $this->actingAs($me, 'sanctum')->getJson(drillUrl($course, $student))->assertOk()->json('data');

    expect($data['certificate']['issued'])->toBeTrue();
});

it('does not flag a certificate the learner has not been issued', function () {
    $me = drillUser();
    [$course] = drillCourse($me);
    $student = User::factory()->create();
    drillEnrol($course, $student);

    $data = $this->actingAs($me, 'sanctum')->getJson(drillUrl($course, $student))->assertOk()->json('data');

    expect($data['certificate']['issued'])->toBeFalse();
});

// ---------------------------------------------------------------- performance

it('does not issue a query per lesson', function () {
    $me = drillUser();

    [$small, $smallLessons] = drillCourse($me, lessonCount: 2);
    $s1 = User::factory()->create();
    $e1 = drillEnrol($small, $s1);
    drillProgress($e1, $smallLessons[0], LessonProgressStatus::Completed);

    $few = drillQueriesFor(fn () => $this->actingAs($me, 'sanctum')->getJson(drillUrl($small, $s1))->assertOk());

    [$big, $bigLessons] = drillCourse($me, lessonCount: 20);
    $s2 = User::factory()->create();
    $e2 = drillEnrol($big, $s2);
    drillProgress($e2, $bigLessons[0], LessonProgressStatus::Completed);

    $many = drillQueriesFor(fn () => $this->actingAs($me, 'sanctum')->getJson(drillUrl($big, $s2))->assertOk());

    // Ten times the lessons must not cost ten times the queries: the whole progress detail is a
    // bounded aggregate set, so a course with 20 lessons issues the same queries as one with 2.
    expect($many)->toBeLessThanOrEqual($few);
});
