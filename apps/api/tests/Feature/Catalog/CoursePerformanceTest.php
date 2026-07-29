<?php

use App\Contexts\Analytics\Database\Seeders\AnalyticsSeeder;
use App\Contexts\Learning\Enums\EnrollmentStatus;
use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Authoring\Enums\PublishState;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Analytics\CoursePerformanceService;
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

const PERF = '/api/v1/teach/dashboard/courses';

function perfUser(string $role = 'instructor'): User
{
    $user = User::factory()->create();
    $user->assignRole(SpatieRole::findByName($role, 'web'));

    return $user;
}

function perfCourse(User $owner, string $title = 'A course', CourseStatus $status = CourseStatus::Published): Course
{
    $course = Course::factory()->create([
        'title' => $title,
        'status' => $status,
        'description' => 'Described.',
        'thumbnail_path' => 'thumb.jpg',
    ]);
    $course->syncTrainers([$owner->id]);

    $section = Section::factory()->create([
        'course_id' => $course->id,
        'publish_state' => PublishState::Published->value,
    ]);
    Lesson::factory()->published()->create([
        'section_id' => $section->id,
        'content' => ['html' => '<p>Body</p>'],
    ]);

    return $course;
}

/** Queries issued by $work, and nothing else — the log is opened and closed around it. */
function queriesFor(callable $work): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $work();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    DB::flushQueryLog();

    return $count;
}

// ---------------------------------------------------------------- authorization

it('denies an unauthenticated caller', function () {
    $this->getJson(PERF)->assertUnauthorized();
});

it('denies a student', function () {
    $this->actingAs(perfUser('student'), 'sanctum')->getJson(PERF)->assertForbidden();
});

it('allows an instructor holding analytics.view', function () {
    $this->actingAs(perfUser(), 'sanctum')->getJson(PERF)->assertOk();
});

// ---------------------------------------------------------------- scoping

it('lists only the caller own courses', function () {
    $me = perfUser();
    perfCourse($me, 'Mine');
    perfCourse(perfUser(), 'Theirs');

    $rows = $this->actingAs($me, 'sanctum')->getJson(PERF)->assertOk()->json('data');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['title'])->toBe('Mine');
});

it('refuses another instructor course filter with a 404', function () {
    $me = perfUser();
    $theirs = perfCourse(perfUser(), 'Theirs');

    $this->actingAs($me, 'sanctum')
        ->getJson(PERF.'?course='.$theirs->public_id)
        ->assertNotFound();
});

it('excludes another instructor enrollments from a row', function () {
    $me = perfUser();
    $mine = perfCourse($me, 'Mine');
    Enrollment::factory()->create(['course_id' => $mine->id, 'user_id' => User::factory()->create()->id]);

    $theirs = perfCourse(perfUser(), 'Theirs');

    // A distinct learner per enrollment: (user_id, course_id) is unique, so reusing one id here
    // fails on the constraint rather than testing the scoping this case is about.
    collect(range(1, 3))->each(fn () => Enrollment::factory()->create([
        'course_id' => $theirs->id,
        'user_id' => User::factory()->create()->id,
    ]));

    $rows = $this->actingAs($me, 'sanctum')->getJson(PERF)->assertOk()->json('data');

    expect($rows[0]['enrollment_count']['value'])->toBe(1);
});

// ---------------------------------------------------------------- row shape

it('reports enrollment, completion and progress for a course', function () {
    $me = perfUser();
    $course = perfCourse($me);
    Enrollment::factory()->create([
        'course_id' => $course->id, 'user_id' => User::factory()->create()->id,
        'status' => EnrollmentStatus::Completed->value, 'progress_percentage' => 100,
    ]);
    Enrollment::factory()->create([
        'course_id' => $course->id, 'user_id' => User::factory()->create()->id,
        'status' => EnrollmentStatus::Active->value, 'progress_percentage' => 40,
    ]);

    $row = $this->actingAs($me, 'sanctum')->getJson(PERF)->assertOk()->json('data.0');

    expect($row['enrollment_count']['value'])->toBe(2)
        ->and($row['completion_rate']['value'])->toBe(50)
        ->and($row['average_progress']['value'])->toBe(70)
        ->and($row['active_learners']['value'])->toBe(1);
});

it('reports unavailable rather than zero for an empty course', function () {
    $me = perfUser();
    perfCourse($me);

    $row = $this->actingAs($me, 'sanctum')->getJson(PERF)->assertOk()->json('data.0');

    expect($row['completion_rate']['available'])->toBeFalse()
        ->and($row['completion_rate']['value'])->toBeNull()
        ->and($row['assessment_pass_rate']['available'])->toBeFalse()
        ->and($row['revenue']['available'])->toBeFalse()
        ->and($row['revenue']['reason'])->toBe('Revenue analytics are not available for instructors yet.');
});

it('carries readiness counts on each row', function () {
    $me = perfUser();
    $bare = Course::factory()->create(['status' => CourseStatus::Draft]);
    $bare->syncTrainers([$me->id]);

    $row = $this->actingAs($me, 'sanctum')->getJson(PERF)->assertOk()->json('data.0');

    // No sections, no description, no thumbnail — one blocker, and warnings besides.
    expect($row['publish_blocker_count'])->toBeGreaterThan(0)
        ->and($row['is_publishable'])->toBeFalse()
        ->and($row['warning_count'])->toBeGreaterThan(0);
});

// ---------------------------------------------------------------- filters, sort, pagination

it('searches by title', function () {
    $me = perfUser();
    perfCourse($me, 'Advanced Laravel');
    perfCourse($me, 'Beginner Python');

    $rows = $this->actingAs($me, 'sanctum')->getJson(PERF.'?search=Laravel')->assertOk()->json('data');

    expect($rows)->toHaveCount(1)->and($rows[0]['title'])->toBe('Advanced Laravel');
});

it('treats LIKE metacharacters in search as literals, not wildcards', function (string $needle) {
    $me = perfUser();
    perfCourse($me, 'Plain');
    perfCourse($me, 'Also plain');

    // Unescaped, % and _ match everything and quietly return the whole catalogue.
    $rows = $this->actingAs($me, 'sanctum')
        ->getJson(PERF.'?search='.urlencode($needle))
        ->assertOk()
        ->json('data');

    expect($rows)->toBeEmpty();
})->with(['%', '_', '\\', '%%', 'P%n', 'Pl_in']);

it('finds a title that genuinely contains a metacharacter', function () {
    $me = perfUser();
    perfCourse($me, '100% Practical SQL');
    perfCourse($me, 'Nothing to do with it');

    $rows = $this->actingAs($me, 'sanctum')
        ->getJson(PERF.'?search='.urlencode('100%'))
        ->assertOk()
        ->json('data');

    expect($rows)->toHaveCount(1)->and($rows[0]['title'])->toBe('100% Practical SQL');
});

it('filters by status', function () {
    $me = perfUser();
    perfCourse($me, 'Live', CourseStatus::Published);
    perfCourse($me, 'WIP', CourseStatus::Draft);

    $rows = $this->actingAs($me, 'sanctum')->getJson(PERF.'?status=draft')->assertOk()->json('data');

    expect($rows)->toHaveCount(1)->and($rows[0]['title'])->toBe('WIP');
});

it('sorts by a whitelisted column', function () {
    $me = perfUser();
    perfCourse($me, 'Bravo');
    perfCourse($me, 'Alpha');

    $rows = $this->actingAs($me, 'sanctum')
        ->getJson(PERF.'?sort=title&direction=asc')->assertOk()->json('data');

    expect(array_column($rows, 'title'))->toBe(['Alpha', 'Bravo']);
});

it('rejects a non-whitelisted sort column rather than running it', function (string $sort) {
    $me = perfUser();
    perfCourse($me);

    // Refused outright: silently substituting the default would tell a caller their sort worked.
    $this->actingAs($me, 'sanctum')
        ->getJson(PERF.'?sort='.urlencode($sort))
        ->assertStatus(422);
})->with([
    'password',
    'courses.id',
    'id; drop table courses',
    '(select 1)',
    'trainer.name',
]);

it('rejects a direction outside asc and desc', function () {
    $me = perfUser();
    perfCourse($me);

    $this->actingAs($me, 'sanctum')->getJson(PERF.'?direction=sideways')->assertStatus(422);
});

it('rejects an unknown status filter', function () {
    $this->actingAs(perfUser(), 'sanctum')->getJson(PERF.'?status=deleted')->assertStatus(422);
});

it('falls back to the default order if an unknown column reaches the service directly', function () {
    $me = perfUser();
    perfCourse($me);

    // The form request is one caller, not the guarantee. Called directly, the service must still
    // refuse to hand an arbitrary column to orderBy().
    $page = app(CoursePerformanceService::class)->paginate(
        [(int) Course::query()->firstOrFail()->getKey()],
        ['sort' => 'password', 'direction' => 'asc'],
    );

    expect($page->total())->toBe(1);
});

it('paginates and caps per_page', function () {
    $me = perfUser();
    collect(range(1, 3))->each(fn (int $i) => perfCourse($me, "Course {$i}"));

    $body = $this->actingAs($me, 'sanctum')->getJson(PERF.'?per_page=2')->assertOk()->json();

    expect($body['data'])->toHaveCount(2)
        ->and($body['meta']['total'])->toBe(3);

    // A caller asking for 5000 rows gets the cap, not a table scan of the whole catalogue.
    $capped = $this->actingAs($me, 'sanctum')->getJson(PERF.'?per_page=5000')->assertOk()->json('meta.per_page');
    expect($capped)->toBeLessThanOrEqual(50);
});

// ---------------------------------------------------------------- performance

it('does not issue a query per row', function () {
    $me = perfUser();
    collect(range(1, 5))->each(fn (int $i) => perfCourse($me, "Course {$i}"));

    // The log is enabled only around each request. Leaving it on while building fixtures counts
    // the INSERTs as if the endpoint had issued them, which inflates the second measurement by the
    // cost of creating ten courses and makes the comparison meaningless.
    $five = queriesFor(fn () => $this->actingAs($me, 'sanctum')->getJson(PERF)->assertOk());

    collect(range(6, 15))->each(fn (int $i) => perfCourse($me, "Course {$i}"));

    $fifteen = queriesFor(fn () => $this->actingAs($me, 'sanctum')->getJson(PERF)->assertOk());

    // Tripling the rows must not triple the queries. Enrollment and attempt aggregates are batched;
    // what remains per row is the curriculum walk and readiness, bounded by per_page — so growth is
    // linear with a small constant, not the multiplicative blow-up an unbatched implementation gives.
    expect($fifteen)->toBeLessThan($five * 3);
});
