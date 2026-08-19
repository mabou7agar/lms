<?php

use App\Contexts\Analytics\Database\Seeders\AnalyticsSeeder;
use App\Contexts\Analytics\Enums\AnalyticsPermission;
use App\Contexts\Learning\Enums\EnrollmentStatus;
use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Assessment\Models\Assessment;
use App\Domains\Assessment\Models\AssessmentAttempt;
use App\Domains\Authoring\Enums\LessonType;
use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IdentitySeeder::class);
    $this->seed(AnalyticsSeeder::class);
});

const OVERVIEW = '/api/v1/teach/dashboard/overview';

function dashUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole(SpatieRole::findByName($role, 'web'));

    return $user;
}

/** A course trained by $owner, with $enrollments learners at the given status. */
function dashCourse(User $owner, CourseStatus $status = CourseStatus::Published): Course
{
    $course = Course::factory()->create(['status' => $status]);
    $course->syncTrainers([$owner->id]);

    return $course;
}

function dashEnrol(Course $course, EnrollmentStatus $status = EnrollmentStatus::Active, int $progress = 50, ?User $user = null): Enrollment
{
    return Enrollment::factory()->create([
        'course_id' => $course->id,
        'user_id' => ($user ?? User::factory()->create())->id,
        'status' => $status->value,
        'progress_percentage' => $progress,
    ]);
}

/** A published quiz lesson on $course, plus a graded attempt with the given outcome. */
function gradedAttempt(Course $course, bool $passed, ?string $submittedAt = null): void
{
    $section = Section::factory()->create([
        'course_id' => $course->id,
        'publish_state' => PublishState::Published->value,
    ]);
    $assessment = Assessment::factory()->create(['course_id' => $course->id, 'status' => 'published']);
    $lesson = Lesson::factory()->create([
        'section_id' => $section->id,
        'type' => LessonType::Quiz->value,
        'publish_state' => PublishState::Published->value,
        'assessment_id' => $assessment->id,
    ]);

    AssessmentAttempt::factory()->create([
        'assessment_id' => $assessment->id,
        'user_id' => User::factory()->create()->id,
        'lesson_id' => $lesson->id,
        'status' => 'graded',
        'passed' => $passed,
        'submitted_at' => $submittedAt ?? now(),
    ]);
}

// ---------------------------------------------------------------- authorization

it('denies an unauthenticated caller', function () {
    $this->getJson(OVERVIEW)->assertUnauthorized();
});

it('denies a student', function () {
    $this->actingAs(dashUser('student'), 'sanctum')->getJson(OVERVIEW)->assertForbidden();
});

it('denies an instructor who does not hold analytics.view', function () {
    SpatieRole::findByName('instructor', 'web')
        ->revokePermissionTo(AnalyticsPermission::ViewAnalytics->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs(dashUser('instructor'), 'sanctum')->getJson(OVERVIEW)->assertForbidden();
});

it('allows an instructor holding analytics.view', function () {
    $this->actingAs(dashUser('instructor'), 'sanctum')->getJson(OVERVIEW)->assertOk();
});

it('allows an admin and a super_admin', function (string $role) {
    $this->actingAs(dashUser($role), 'sanctum')->getJson(OVERVIEW)->assertOk();
})->with(['admin', 'super_admin']);

// ---------------------------------------------------------------- scoping

it('counts only the caller own courses', function () {
    $me = dashUser('instructor');
    dashCourse($me);
    dashCourse($me, CourseStatus::Draft);
    dashCourse(dashUser('instructor')); // another instructor's course

    $this->actingAs($me, 'sanctum')->getJson(OVERVIEW)
        ->assertOk()
        ->assertJsonPath('data.total_courses.value', 2)
        ->assertJsonPath('data.published_courses.value', 1)
        ->assertJsonPath('data.draft_courses.value', 1);
});

it('excludes another instructor learners from the totals', function () {
    $me = dashUser('instructor');
    $mine = dashCourse($me);
    dashEnrol($mine);

    $theirs = dashCourse(dashUser('instructor'));
    dashEnrol($theirs);
    dashEnrol($theirs);

    $this->actingAs($me, 'sanctum')->getJson(OVERVIEW)
        ->assertOk()
        ->assertJsonPath('data.total_learners.value', 1);
});

it('excludes another instructor attempts from the pass rate', function () {
    $me = dashUser('instructor');
    gradedAttempt(dashCourse($me), passed: true);

    // Two failures on a course this instructor does not train must not drag their rate down.
    $theirs = dashCourse(dashUser('instructor'));
    gradedAttempt($theirs, passed: false);
    gradedAttempt($theirs, passed: false);

    $this->actingAs($me, 'sanctum')->getJson(OVERVIEW)
        ->assertOk()
        ->assertJsonPath('data.assessment_pass_rate.value', 100);
});

it('hides a course the caller does not train behind a 404', function () {
    $me = dashUser('instructor');
    $theirs = dashCourse(dashUser('instructor'));

    // 404 not 403, matching the portal convention: a 403 would confirm the course exists and turn
    // this into an oracle for probing other instructors' catalogues.
    $this->actingAs($me, 'sanctum')
        ->getJson(OVERVIEW.'?course='.$theirs->public_id)
        ->assertNotFound();
});

it('narrows to a single owned course', function () {
    $me = dashUser('instructor');
    $a = dashCourse($me);
    dashEnrol($a);
    $b = dashCourse($me);
    dashEnrol($b);
    dashEnrol($b);

    $this->actingAs($me, 'sanctum')->getJson(OVERVIEW.'?course='.$a->public_id)
        ->assertOk()
        ->assertJsonPath('data.total_courses.value', 1)
        ->assertJsonPath('data.total_learners.value', 1);
});

// ---------------------------------------------------------------- accuracy

it('counts a learner enrolled in several of the same instructor courses once', function () {
    $me = dashUser('instructor');
    $learner = User::factory()->create();
    dashEnrol(dashCourse($me), user: $learner);
    dashEnrol(dashCourse($me), user: $learner);

    // total_learners is UNIQUE learners. Enrollment counts belong at course level, where the
    // distinction cannot mislead.
    $this->actingAs($me, 'sanctum')->getJson(OVERVIEW)
        ->assertOk()
        ->assertJsonPath('data.total_learners.value', 1);
});

it('computes completion rate and average progress from enrollments', function () {
    $me = dashUser('instructor');
    $course = dashCourse($me);
    dashEnrol($course, EnrollmentStatus::Completed, 100);
    dashEnrol($course, EnrollmentStatus::Active, 50);
    dashEnrol($course, EnrollmentStatus::Active, 0);

    $this->actingAs($me, 'sanctum')->getJson(OVERVIEW)
        ->assertOk()
        ->assertJsonPath('data.completion_rate.value', 33)
        ->assertJsonPath('data.average_progress.value', 50);
});

it('counts as active only started, unfinished enrollments', function () {
    $me = dashUser('instructor');
    $course = dashCourse($me);
    dashEnrol($course, EnrollmentStatus::Active, 40);   // counts
    dashEnrol($course, EnrollmentStatus::Active, 0);    // enrolled but never started
    dashEnrol($course, EnrollmentStatus::Completed, 100); // finished
    dashEnrol($course, EnrollmentStatus::Cancelled, 20);  // dropped

    $this->actingAs($me, 'sanctum')->getJson(OVERVIEW)
        ->assertOk()
        ->assertJsonPath('data.active_learners.value', 1);
});

it('reports no-data rather than zero when there are no enrollments', function () {
    $me = dashUser('instructor');
    dashCourse($me);

    // 0% completion would read as "nobody is finishing". Nobody has enrolled.
    $this->actingAs($me, 'sanctum')->getJson(OVERVIEW)
        ->assertOk()
        ->assertJsonPath('data.completion_rate.value', null)
        ->assertJsonPath('data.completion_rate.available', false)
        ->assertJsonPath('data.average_progress.available', false);
});

it('reports no-data rather than zero when no quiz attempt has been graded', function () {
    $me = dashUser('instructor');
    dashCourse($me);

    $this->actingAs($me, 'sanctum')->getJson(OVERVIEW)
        ->assertOk()
        ->assertJsonPath('data.assessment_pass_rate.value', null)
        ->assertJsonPath('data.assessment_pass_rate.available', false);
});

it('computes a pass rate from mixed graded attempts', function () {
    $me = dashUser('instructor');
    $course = dashCourse($me);
    gradedAttempt($course, passed: true);
    gradedAttempt($course, passed: true);
    gradedAttempt($course, passed: false);

    $this->actingAs($me, 'sanctum')->getJson(OVERVIEW)
        ->assertOk()
        ->assertJsonPath('data.assessment_pass_rate.value', 67);
});

// ---------------------------------------------------------------- filtering

it('excludes attempts submitted outside the requested window', function () {
    $me = dashUser('instructor');
    $course = dashCourse($me);
    gradedAttempt($course, passed: true, submittedAt: '2026-01-10 12:00:00');
    gradedAttempt($course, passed: false, submittedAt: '2025-06-01 12:00:00'); // before
    gradedAttempt($course, passed: false, submittedAt: '2026-09-01 12:00:00'); // after

    $this->actingAs($me, 'sanctum')
        ->getJson(OVERVIEW.'?date_from=2026-01-01&date_to=2026-01-31')
        ->assertOk()
        ->assertJsonPath('data.assessment_pass_rate.value', 100);
});

it('rejects a window that ends before it starts', function () {
    $this->actingAs(dashUser('instructor'), 'sanctum')
        ->getJson(OVERVIEW.'?date_from=2026-05-01&date_to=2026-04-01')
        ->assertStatus(422);
});

it('does not date-filter course status counts', function () {
    $me = dashUser('instructor');
    dashCourse($me);

    // A course's status is a current fact, not an event inside a window.
    $this->actingAs($me, 'sanctum')
        ->getJson(OVERVIEW.'?date_from=2026-01-01&date_to=2026-01-02')
        ->assertOk()
        ->assertJsonPath('data.total_courses.value', 1);
});

// ---------------------------------------------------------------- unavailable metrics

it('reports revenue as unavailable with a reason, never as zero', function () {
    $this->actingAs(dashUser('instructor'), 'sanctum')->getJson(OVERVIEW)
        ->assertOk()
        ->assertJsonPath('data.revenue.value', null)
        ->assertJsonPath('data.revenue.available', false)
        ->assertJsonPath('data.revenue.reason', 'Revenue analytics are not available for instructors yet.');
});

it('reports at-risk learners as unavailable with a reason', function () {
    $this->actingAs(dashUser('instructor'), 'sanctum')->getJson(OVERVIEW)
        ->assertOk()
        ->assertJsonPath('data.at_risk_learners.value', null)
        ->assertJsonPath('data.at_risk_learners.available', false)
        ->assertJsonPath('data.at_risk_learners.reason', 'At-risk learner detection is not configured.');
});

it('emits every metric through the same availability envelope', function () {
    $body = $this->actingAs(dashUser('instructor'), 'sanctum')->getJson(OVERVIEW)
        ->assertOk()
        ->json('data');

    $expected = [
        'total_courses', 'published_courses', 'draft_courses', 'archived_courses',
        'total_learners', 'active_learners', 'completion_rate', 'average_progress',
        'assessment_pass_rate', 'revenue', 'at_risk_learners',
    ];

    expect(array_keys($body))->toEqualCanonicalizing($expected);

    // A uniform shape is what lets the client key off a flag instead of special-casing metric
    // names, so a metric changing availability needs no frontend change.
    foreach ($body as $key => $metric) {
        expect($metric)->toHaveKeys(['value', 'available'], "metric `{$key}`");
    }
});

it('never exposes a revenue figure in the payload', function () {
    $me = dashUser('instructor');
    dashEnrol(dashCourse($me));

    $body = $this->actingAs($me, 'sanctum')->getJson(OVERVIEW)->assertOk()->getContent();

    expect($body)->not->toContain('_minor')
        ->and($body)->not->toContain('currency');
});
