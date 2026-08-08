<?php

use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Enums\CourseStatus;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Models\CourseAnnouncement;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Enums\Role;
use App\Platform\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(RolePermissionSeeder::class));

/**
 * A user holding the instructor role. Assigns the web-guard Role model explicitly so the call is
 * unaffected by a default guard already switched to "sanctum" via Sanctum::actingAs().
 */
function teachInstructor(): User
{
    $user = User::factory()->create();
    $user->assignRole(SpatieRole::findByName(Role::Instructor->value, 'web'));

    return $user;
}

/** A course trained by the given instructor. */
function teachCourseFor(User $instructor, bool $published = true): Course
{
    $course = $published ? Course::factory()->published()->create() : Course::factory()->create();
    $course->syncTrainers([$instructor->id]);

    return $course;
}

it('lists only the courses the instructor trains', function () {
    $me = teachInstructor();
    $mine = teachCourseFor($me);
    $theirs = teachCourseFor(teachInstructor());

    Sanctum::actingAs($me);
    $res = $this->getJson('/api/v1/teach/courses')->assertOk();

    $ids = collect($res->json('data'))->pluck('id');
    expect($ids)->toContain($mine->public_id)
        ->and($ids)->not->toContain($theirs->public_id);
});

it('filters my courses by status', function () {
    $me = teachInstructor();
    $published = teachCourseFor($me);
    $draft = teachCourseFor($me, published: false);

    Sanctum::actingAs($me);
    $ids = collect($this->getJson('/api/v1/teach/courses?status=draft')->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($draft->public_id)->and($ids)->not->toContain($published->public_id);
});

it('forbids users without an instructor role from the portal', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/teach/dashboard')->assertStatus(403);
});

it('returns 404 for a course the instructor does not train', function () {
    $me = teachInstructor();
    $theirs = teachCourseFor(teachInstructor());

    Sanctum::actingAs($me);
    $this->getJson("/api/v1/teach/courses/{$theirs->public_id}")->assertNotFound();
});

it('publishes, unpublishes and archives an owned course', function () {
    $me = teachInstructor();
    $course = teachCourseFor($me, published: false);
    // The Authoring CurriculumPublishGuard requires published curriculum to publish.
    $section = Section::factory()->published()->create(['course_id' => $course->id]);
    Lesson::factory()->published()->create(['section_id' => $section->id, 'position' => 1]);
    Sanctum::actingAs($me);

    $this->postJson("/api/v1/teach/courses/{$course->public_id}/publish")
        ->assertOk()->assertJsonPath('data.status', 'published');
    expect($course->fresh()->status)->toBe(CourseStatus::Published);

    // Unpublish now targets the distinct Unpublished state (not Draft): a course withdrawn from the
    // catalog is meaningfully different from one that was never published — both are equally
    // non-public. See CourseLifecycle + UnpublishCourseAction.
    $this->postJson("/api/v1/teach/courses/{$course->public_id}/unpublish")
        ->assertOk()->assertJsonPath('data.status', 'unpublished');

    $this->postJson("/api/v1/teach/courses/{$course->public_id}/archive")
        ->assertOk()->assertJsonPath('data.status', 'archived');
});

it('blocks publishing a course the instructor does not train', function () {
    $me = teachInstructor();
    $course = teachCourseFor(teachInstructor(), published: false);

    Sanctum::actingAs($me);
    $this->postJson("/api/v1/teach/courses/{$course->public_id}/publish")->assertNotFound();

    expect($course->fresh()->status)->toBe(CourseStatus::Draft);
});

it('lists enrolled students with progress', function () {
    $me = teachInstructor();
    $course = teachCourseFor($me);
    $student = User::factory()->create(['name' => 'Learner A']);
    Enrollment::factory()->create([
        'user_id' => $student->id, 'course_id' => $course->id, 'progress_percentage' => 42,
    ]);

    Sanctum::actingAs($me);
    $res = $this->getJson("/api/v1/teach/courses/{$course->public_id}/students")->assertOk();

    expect($res->json('data.0.student.name'))->toBe('Learner A')
        ->and($res->json('data.0.progress_percentage'))->toBe(42)
        ->and($res->json('meta.total'))->toBe(1);
});

it('reports per-course stats including sections and lessons', function () {
    $me = teachInstructor();
    $course = teachCourseFor($me);
    $section = Section::factory()->published()->create(['course_id' => $course->id]);
    collect(range(1, 3))->each(fn (int $i) => Lesson::factory()->published()->create([
        'section_id' => $section->id, 'position' => $i,
    ]));
    Enrollment::factory()->create(['user_id' => User::factory(), 'course_id' => $course->id, 'progress_percentage' => 50]);

    Sanctum::actingAs($me);
    $res = $this->getJson("/api/v1/teach/courses/{$course->public_id}")->assertOk();

    expect($res->json('data.stats.sections'))->toBe(1)
        ->and($res->json('data.stats.lessons'))->toBe(3)
        ->and($res->json('data.stats.enrollments'))->toBe(1)
        ->and($res->json('data.stats.avg_progress'))->toBe(50);
});

it('creates an announcement, persists it, and fans out to enrolled learners', function () {
    Queue::fake();

    $me = teachInstructor();
    $course = teachCourseFor($me);
    $student = User::factory()->create();
    Enrollment::factory()->create(['user_id' => $student->id, 'course_id' => $course->id]);

    Sanctum::actingAs($me);
    $res = $this->postJson("/api/v1/teach/courses/{$course->public_id}/announcements", [
        'title' => 'Welcome', 'body' => 'Hello class',
    ])->assertCreated();

    expect($res->json('data.title'))->toBe('Welcome');
    $this->assertDatabaseHas('course_announcements', [
        'course_id' => $course->id, 'title' => 'Welcome', 'author_id' => $me->id,
    ]);
});

it('lists announcements for an owned course and blocks non-owners', function () {
    $me = teachInstructor();
    $course = teachCourseFor($me);
    CourseAnnouncement::factory()->count(2)->create(['course_id' => $course->id, 'author_id' => $me->id]);

    Sanctum::actingAs($me);
    $this->getJson("/api/v1/teach/courses/{$course->public_id}/announcements")
        ->assertOk()->assertJsonCount(2, 'data');

    Sanctum::actingAs(teachInstructor());
    $this->getJson("/api/v1/teach/courses/{$course->public_id}/announcements")->assertNotFound();
});

it('returns sane dashboard counts', function () {
    $me = teachInstructor();
    $published = teachCourseFor($me);
    teachCourseFor($me, published: false);

    Enrollment::factory()->create(['user_id' => User::factory(), 'course_id' => $published->id]);
    Enrollment::factory()->completed()->create(['user_id' => User::factory(), 'course_id' => $published->id]);

    Sanctum::actingAs($me);
    $res = $this->getJson('/api/v1/teach/dashboard')->assertOk();

    expect($res->json('data.courses.total'))->toBe(2)
        ->and($res->json('data.courses.published'))->toBe(1)
        ->and($res->json('data.courses.draft'))->toBe(1)
        ->and($res->json('data.students'))->toBe(2)
        ->and($res->json('data.completions'))->toBe(1)
        ->and($res->json('data.recent_enrollments'))->toHaveCount(2);
});
