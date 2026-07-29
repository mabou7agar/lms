<?php

use App\Contexts\Analytics\Database\Seeders\AnalyticsSeeder;
use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Analytics\InstructorActivityService;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\IdentitySeeder;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(IdentitySeeder::class);
    $this->seed(AnalyticsSeeder::class);
});

const ACTIVITY = '/api/v1/teach/dashboard/activity';
const ALERTS = '/api/v1/teach/dashboard/alerts';

function actUser(string $role = 'instructor'): User
{
    $user = User::factory()->create();
    $user->assignRole(SpatieRole::findByName($role, 'web'));

    return $user;
}

function actCourse(User $owner, array $attributes = [], bool $withCurriculum = true): Course
{
    $course = Course::factory()->create($attributes + [
        'description' => 'Described.',
        'thumbnail_path' => 'thumb.jpg',
    ]);
    $course->syncTrainers([$owner->id]);

    if ($withCurriculum) {
        $section = Section::factory()->create([
            'course_id' => $course->id,
            'publish_state' => PublishState::Published->value,
        ]);
        Lesson::factory()->published()->create([
            'section_id' => $section->id,
            'content' => ['html' => '<p>Body</p>'],
        ]);
    }

    return $course;
}

/** Queries issued by one alerts request, and nothing else. */
function alertQueries(object $test, User $actor): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $test->actingAs($actor, 'sanctum')->getJson(ALERTS)->assertOk();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();

    return $count;
}

// ---------------------------------------------------------------- authorization

it('denies activity and alerts to a student', function (string $uri) {
    $this->actingAs(actUser('student'), 'sanctum')->getJson($uri)->assertForbidden();
})->with([ACTIVITY, ALERTS]);

it('denies an unauthenticated caller', function (string $uri) {
    $this->getJson($uri)->assertUnauthorized();
})->with([ACTIVITY, ALERTS]);

// ---------------------------------------------------------------- activity

it('lists recently edited courses, most recent first', function () {
    $me = actUser();
    $old = actCourse($me, ['title' => 'Old']);
    $new = actCourse($me, ['title' => 'New']);
    $old->forceFill(['updated_at' => now()->subDays(5)])->saveQuietly();

    $body = $this->actingAs($me, 'sanctum')->getJson(ACTIVITY)->assertOk()->json('data');

    expect(array_column($body['recently_edited'], 'title'))->toBe(['New', 'Old']);
});

it('excludes another instructor courses from activity', function () {
    $me = actUser();
    actCourse($me, ['title' => 'Mine']);
    actCourse(actUser(), ['title' => 'Theirs']);

    $body = $this->actingAs($me, 'sanctum')->getJson(ACTIVITY)->assertOk()->json('data');

    expect(array_column($body['recently_edited'], 'title'))->toBe(['Mine']);
});

it('lists only courses that have actually been published', function () {
    $me = actUser();
    actCourse($me, ['title' => 'Shipped', 'status' => CourseStatus::Published, 'published_at' => now()]);
    actCourse($me, ['title' => 'Never', 'status' => CourseStatus::Draft, 'published_at' => null]);

    $body = $this->actingAs($me, 'sanctum')->getJson(ACTIVITY)->assertOk()->json('data');

    expect(array_column($body['recently_published'], 'title'))->toBe(['Shipped']);
});

// ---------------------------------------------------------------- alerts

it('reports a course with publish blockers and names the first one', function () {
    $me = actUser();
    actCourse($me, ['title' => 'Empty', 'status' => CourseStatus::Draft], withCurriculum: false);

    $body = $this->actingAs($me, 'sanctum')->getJson(ALERTS)->assertOk()->json('data');

    expect($body['publish_blockers'])->toHaveCount(1)
        ->and($body['publish_blockers'][0]['title'])->toBe('Empty')
        ->and($body['publish_blockers'][0]['first_blocker'])->toBe('The course has no sections.');
});

it('does not report blockers for a ready course', function () {
    $me = actUser();
    actCourse($me, ['status' => CourseStatus::Published]);

    $body = $this->actingAs($me, 'sanctum')->getJson(ALERTS)->assertOk()->json('data');

    expect($body['publish_blockers'])->toBeEmpty();
});

it('excludes another instructor blocked courses', function () {
    $me = actUser();
    actCourse($me, ['status' => CourseStatus::Published]);
    actCourse(actUser(), ['title' => 'Their mess'], withCurriculum: false);

    $body = $this->actingAs($me, 'sanctum')->getJson(ALERTS)->assertOk()->json('data');

    expect($body['publish_blockers'])->toBeEmpty();
});

it('flags a stale draft but not a recently touched one', function () {
    $me = actUser();
    $stale = actCourse($me, ['title' => 'Forgotten', 'status' => CourseStatus::Draft]);
    actCourse($me, ['title' => 'Fresh', 'status' => CourseStatus::Draft]);

    $stale->forceFill([
        'updated_at' => now()->subDays(InstructorActivityService::STALE_DRAFT_DAYS + 1),
    ])->saveQuietly();

    $body = $this->actingAs($me, 'sanctum')->getJson(ALERTS)->assertOk()->json('data');

    expect(array_column($body['stale_drafts'], 'title'))->toBe(['Forgotten']);
});

it('flags a published course with no learners, and ignores an empty draft', function () {
    $me = actUser();
    actCourse($me, ['title' => 'Nobody', 'status' => CourseStatus::Published]);
    actCourse($me, ['title' => 'Draft', 'status' => CourseStatus::Draft]);

    $enrolled = actCourse($me, ['title' => 'Popular', 'status' => CourseStatus::Published]);
    Enrollment::factory()->create(['course_id' => $enrolled->id, 'user_id' => User::factory()->create()->id]);

    // A draft with no learners is the definition of a draft, not a problem worth alerting on.
    $body = $this->actingAs($me, 'sanctum')->getJson(ALERTS)->assertOk()->json('data');

    expect(array_column($body['courses_without_learners'], 'title'))->toBe(['Nobody']);
});

it('reports unavailable signals honestly rather than as empty lists', function () {
    $body = $this->actingAs(actUser(), 'sanctum')->getJson(ALERTS)->assertOk()->json('data');

    // Not fabricated, and not silently absent either — the panel can say why.
    expect($body['at_risk_learners']['available'])->toBeFalse()
        ->and($body['at_risk_learners']['reason'])->toBe('At-risk learner detection is not configured.')
        ->and($body['failed_publishes']['available'])->toBeFalse()
        ->and($body['failed_publishes']['reason'])->toBe('Failed publish attempts are not recorded.');
});

it('never emits a revenue figure in activity or alerts', function (string $uri) {
    $me = actUser();
    actCourse($me);

    $body = $this->actingAs($me, 'sanctum')->getJson($uri)->assertOk()->getContent();

    expect($body)->not->toContain('_minor')->and($body)->not->toContain('revenue');
})->with([ACTIVITY, ALERTS]);

// ---------------------------------------------------------------- bounding

it('reports full coverage when the catalogue is small', function () {
    $me = actUser();
    actCourse($me);
    actCourse($me);

    $coverage = $this->actingAs($me, 'sanctum')->getJson(ALERTS)->assertOk()->json('data.readiness_coverage');

    expect($coverage['total_count'])->toBe(2)
        ->and($coverage['evaluated_count'])->toBe(2)
        ->and($coverage['truncated'])->toBeFalse();
});

it('bounds the readiness sweep and says so instead of implying a clean sweep', function () {
    $me = actUser();
    $limit = InstructorActivityService::MAX_EVALUATED_COURSES;

    $courses = Course::factory()->count($limit + 5)->create(['status' => CourseStatus::Draft]);
    foreach ($courses as $course) {
        $course->syncTrainers([$me->id]);
    }

    $coverage = $this->actingAs($me, 'sanctum')->getJson(ALERTS)->assertOk()->json('data.readiness_coverage');

    // The panel must be able to say "the 50 most recently edited of 55" rather than presenting a
    // partial sweep as a clean bill of health.
    expect($coverage['evaluated_count'])->toBe($limit)
        ->and($coverage['total_count'])->toBe($limit + 5)
        ->and($coverage['truncated'])->toBeTrue()
        ->and($coverage['limit'])->toBe($limit);
});

it('keeps the cheap alerts complete across the whole scope even when readiness is truncated', function () {
    $me = actUser();
    $limit = InstructorActivityService::MAX_EVALUATED_COURSES;

    $courses = Course::factory()->count($limit + 5)->create(['status' => CourseStatus::Draft]);
    foreach ($courses as $course) {
        $course->syncTrainers([$me->id]);
        $course->forceFill(['updated_at' => now()->subDays(InstructorActivityService::STALE_DRAFT_DAYS + 1)])->saveQuietly();
    }

    // Stale drafts come from a column, not an evaluation, so the readiness bound must not silently
    // truncate them too.
    $body = $this->actingAs($me, 'sanctum')->getJson(ALERTS)->assertOk()->json('data');

    expect($body['stale_drafts'])->toHaveCount($limit + 5);
});

it('does not grow queries linearly as the catalogue grows', function () {
    $me = actUser();
    collect(range(1, 5))->each(fn () => actCourse($me));

    // Measured around the request only — fixture INSERTs must not be counted as endpoint queries.
    $five = alertQueries($this, $me);

    collect(range(1, 25))->each(fn () => actCourse($me));

    $thirty = alertQueries($this, $me);

    // Six times the courses must not cost six times the queries. Enrollment stats are one grouped
    // query regardless of size, and readiness is capped — so growth flattens rather than scaling.
    expect($thirty)->toBeLessThan($five * 6);
});
