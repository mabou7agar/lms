<?php

use App\Contexts\Analytics\Database\Seeders\AnalyticsSeeder;
use App\Contexts\Analytics\Enums\AnalyticsPermission;
use App\Contexts\Learning\Enums\EnrollmentStatus;
use App\Contexts\Learning\Enums\LessonProgressStatus;
use App\Contexts\Learning\Models\Enrollment;
use App\Contexts\Learning\Models\LearningSession;
use App\Contexts\Learning\Models\LessonProgress;
use App\Contexts\Learning\Models\LessonVideoProgress;
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
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IdentitySeeder::class);
    $this->seed(AnalyticsSeeder::class);
});

function insightUser(string $role = 'instructor'): User
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
function insightCourse(User $owner, int $lessonCount = 2): array
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

function insightEnrol(Course $course, int $progress = 50, EnrollmentStatus $status = EnrollmentStatus::Active, ?User $user = null): Enrollment
{
    return Enrollment::factory()->create([
        'course_id' => $course->id,
        'user_id' => ($user ?? User::factory()->create())->id,
        'status' => $status->value,
        'progress_percentage' => $progress,
    ]);
}

function insightQueriesFor(callable $work): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $work();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();

    return $count;
}

function analyticsUrl(Course $course): string
{
    return "/api/v1/teach/courses/{$course->public_id}/analytics";
}

// ---------------------------------------------------------------- authorization

it('denies an unauthenticated caller', function () {
    [$course] = insightCourse(insightUser());
    $this->getJson(analyticsUrl($course))->assertUnauthorized();
});

it('denies a student', function () {
    $me = insightUser('student');
    [$course] = insightCourse(insightUser());
    $this->actingAs($me, 'sanctum')->getJson(analyticsUrl($course))->assertForbidden();
});

it('denies an instructor who does not hold analytics.view', function () {
    SpatieRole::findByName('instructor', 'web')->revokePermissionTo(AnalyticsPermission::ViewAnalytics->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $me = insightUser('instructor');
    [$course] = insightCourse($me);

    $this->actingAs($me, 'sanctum')->getJson(analyticsUrl($course))->assertForbidden();
});

it('returns 404 for a course the caller does not train', function () {
    $me = insightUser();
    [$theirs] = insightCourse(insightUser());

    $this->actingAs($me, 'sanctum')->getJson(analyticsUrl($theirs))->assertNotFound();
});

it('allows an instructor holding analytics.view on an owned course', function () {
    $me = insightUser();
    [$course] = insightCourse($me);

    $this->actingAs($me, 'sanctum')->getJson(analyticsUrl($course))->assertOk();
});

it('never exposes a revenue figure', function () {
    $me = insightUser();
    [$course] = insightCourse($me);
    insightEnrol($course);

    $body = $this->actingAs($me, 'sanctum')->getJson(analyticsUrl($course))->assertOk()->getContent();

    expect($body)->not->toContain('revenue')
        ->and($body)->not->toContain('currency');
});

// ---------------------------------------------------------------- watch time

it('reports total and per-learner average watch time', function () {
    $me = insightUser();
    [$course, $lessons] = insightCourse($me);

    $e1 = insightEnrol($course);
    $e2 = insightEnrol($course);
    LessonVideoProgress::create([
        'enrollment_id' => $e1->id, 'user_id' => $e1->user_id, 'lesson_id' => $lessons[0]->id,
        'position_seconds' => 100, 'watched_seconds' => 100, 'completed' => false,
    ]);
    LessonVideoProgress::create([
        'enrollment_id' => $e2->id, 'user_id' => $e2->user_id, 'lesson_id' => $lessons[0]->id,
        'position_seconds' => 200, 'watched_seconds' => 200, 'completed' => false,
    ]);

    $data = $this->actingAs($me, 'sanctum')->getJson(analyticsUrl($course))->assertOk()->json('data');

    expect($data['watch_time']['total_watched_seconds']['value'])->toBe(300)
        ->and($data['watch_time']['avg_watched_seconds_per_learner']['value'])->toBe(150);
});

it('reports avg watch time as no-data when nobody is enrolled', function () {
    $me = insightUser();
    [$course] = insightCourse($me);

    $data = $this->actingAs($me, 'sanctum')->getJson(analyticsUrl($course))->assertOk()->json('data');

    expect($data['watch_time']['avg_watched_seconds_per_learner']['available'])->toBeFalse()
        ->and($data['watch_time']['avg_watched_seconds_per_learner']['value'])->toBeNull();
});

// ---------------------------------------------------------------- drop-off

it('flags a lesson started but not completed', function () {
    $me = insightUser();
    [$course, $lessons] = insightCourse($me);
    [$l1] = $lessons;

    // Three learners begin L1; only one finishes it.
    $completed = insightEnrol($course);
    LessonProgress::create(['enrollment_id' => $completed->id, 'lesson_id' => $l1->id, 'status' => LessonProgressStatus::Completed->value, 'position_seconds' => 0, 'completed_at' => now()]);

    foreach (range(1, 2) as $ignored) {
        $stuck = insightEnrol($course);
        LessonProgress::create(['enrollment_id' => $stuck->id, 'lesson_id' => $l1->id, 'status' => LessonProgressStatus::InProgress->value, 'position_seconds' => 0]);
    }

    $rows = $this->actingAs($me, 'sanctum')->getJson(analyticsUrl($course))->assertOk()->json('data.lesson_drop_off');

    $row = collect($rows)->firstWhere('lesson.id', $l1->public_id);

    expect($row['started'])->toBe(3)
        ->and($row['completed'])->toBe(1)
        ->and($row['drop_off'])->toBe(2);
});

// ---------------------------------------------------------------- inactive learners

it('counts learners with no activity inside the window as inactive', function () {
    $me = insightUser();
    [$course, $lessons] = insightCourse($me);

    $recent = insightEnrol($course);
    LearningSession::create(['user_id' => $recent->user_id, 'course_id' => $course->id, 'last_lesson_id' => $lessons[0]->id, 'last_activity_at' => now()]);

    $stale = insightEnrol($course);
    LearningSession::create(['user_id' => $stale->user_id, 'course_id' => $course->id, 'last_lesson_id' => $lessons[0]->id, 'last_activity_at' => now()->subDays(30)]);

    $data = $this->actingAs($me, 'sanctum')->getJson(analyticsUrl($course).'?inactive_days=14')->assertOk()->json('data');

    expect($data['inactive_learners']['count']['value'])->toBe(1)
        ->and($data['inactive_learners']['window_days'])->toBe(14);
});

// ---------------------------------------------------------------- completion distribution

it('buckets learners by completion percentage', function () {
    $me = insightUser();
    [$course] = insightCourse($me);

    insightEnrol($course, progress: 0);
    insightEnrol($course, progress: 10);
    insightEnrol($course, progress: 40);
    insightEnrol($course, progress: 60);
    insightEnrol($course, progress: 85);
    insightEnrol($course, progress: 100, status: EnrollmentStatus::Completed);

    $dist = $this->actingAs($me, 'sanctum')->getJson(analyticsUrl($course))->assertOk()->json('data.completion_distribution');

    expect($dist)->toBe([
        '0' => 1, '1-25' => 1, '26-50' => 1, '51-75' => 1, '76-99' => 1, '100' => 1,
    ]);
});

// ---------------------------------------------------------------- certificates

it('reports the issued-certificate count for the course', function () {
    $me = insightUser();
    [$course] = insightCourse($me);
    $learner = insightEnrol($course, progress: 100, status: EnrollmentStatus::Completed);

    Certificate::factory()->create([
        'course_id' => $course->id, 'user_id' => $learner->user_id, 'status' => CertificateStatus::Issued->value,
    ]);

    $data = $this->actingAs($me, 'sanctum')->getJson(analyticsUrl($course))->assertOk()->json('data');

    expect($data['certificates_issued']['value'])->toBe(1);
});

// ---------------------------------------------------------------- performance

it('does not issue a query per learner', function () {
    $me = insightUser();
    [$course] = insightCourse($me);
    insightEnrol($course);
    insightEnrol($course);

    $few = insightQueriesFor(fn () => $this->actingAs($me, 'sanctum')->getJson(analyticsUrl($course))->assertOk());

    collect(range(1, 8))->each(fn () => insightEnrol($course));

    $many = insightQueriesFor(fn () => $this->actingAs($me, 'sanctum')->getJson(analyticsUrl($course))->assertOk());

    // Every figure is a whole-course aggregate, so quadrupling the roster must not add a single
    // query — the endpoint does not scale per learner.
    expect($many)->toBeLessThanOrEqual($few);
});
