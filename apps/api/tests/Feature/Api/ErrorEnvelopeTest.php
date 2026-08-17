<?php

declare(strict_types=1);

use App\Contexts\Learning\Enums\EnrollmentSource;
use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Authoring\Models\CourseResource;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Models\Course;
use App\Platform\Identity\Database\Seeders\RolePermissionSeeder;
use App\Platform\Identity\Models\User;
use App\Platform\Media\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * One error shape for the whole API.
 *
 * Domain exceptions have always rendered the standard envelope. Framework HTTP exceptions did not —
 * a 403 from an authorization gate or a 404 from a missing record came back as a bare
 * `{"message": "..."}` with no code and no correlation id, so a client could branch on the status
 * and nothing else. These pin both halves: every refusal carries a code, and the refusals a learner
 * is most likely to hit carry one that says WHY rather than only that it happened.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

/** A published course with one lesson, and a learner enrolled in it. */
function envelopeCourse(): array
{
    $course = Course::factory()->published()->create();
    $section = Section::factory()->published()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->published()->create(['section_id' => $section->id]);
    $learner = User::factory()->create();

    Enrollment::create([
        'course_id' => $course->id,
        'user_id' => $learner->id,
        'status' => 'active',
        'source' => EnrollmentSource::Free->value,
        'enrolled_at' => now(),
    ]);

    return [$course, $lesson, $learner];
}

it('renders a framework 404 as the standard envelope', function (): void {
    Sanctum::actingAs(User::factory()->create());

    // A well-formed id for a course that does not exist. (A MALFORMED id is a different story: the
    // public_id lookup hands it straight to Postgres and the uuid cast raises a 500. That predates
    // this wave and is not a refusal, so it is not what this renderer is for.)
    $this->getJson('/api/v1/courses/'.Str::uuid7()->toString().'/questions')
        ->assertStatus(404)
        ->assertJsonStructure(['error' => ['code', 'message', 'details', 'correlation_id', 'timestamp']])
        ->assertJsonPath('error.code', 'HTTP_NOT_FOUND');
});

it('keeps the thrower’s own message rather than replacing it with a status word', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/courses/'.Str::uuid7()->toString().'/questions')
        ->assertJsonPath('error.message', 'Course not found.');
});

it('tells a stranger they have no access, with a code', function (): void {
    [$course] = envelopeCourse();
    Sanctum::actingAs(User::factory()->create());

    $this->getJson("/api/v1/courses/{$course->public_id}/questions")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'COURSE_ACCESS_DENIED');
});

it('tells a learner whose window closed that it EXPIRED, not that they were never enrolled', function (): void {
    [$course, , $learner] = envelopeCourse();
    Enrollment::where('user_id', $learner->id)->update(['expires_at' => now()->subDay()]);

    Sanctum::actingAs($learner);

    // Same 403, different code: one renews, the other buys.
    $this->getJson("/api/v1/courses/{$course->public_id}/questions")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'LEARNING_ACCESS_EXPIRED');
});

it('refuses asking and answering with the same coded refusal', function (): void {
    [$course, , $learner] = envelopeCourse();
    Enrollment::where('user_id', $learner->id)->update(['expires_at' => now()->subDay()]);

    Sanctum::actingAs($learner);

    $this->postJson("/api/v1/courses/{$course->public_id}/questions", [
        'title' => 'Still there?',
        'body' => 'A question from someone whose access ran out.',
    ])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'LEARNING_ACCESS_EXPIRED');
});

it('separates a file that is view-only from a course the caller cannot reach', function (): void {
    [$course, $lesson, $learner] = envelopeCourse();

    $asset = MediaAsset::factory()->create();
    $resource = CourseResource::create([
        'course_id' => $course->id,
        'lesson_id' => $lesson->id,
        'media_asset_id' => $asset->id,
        'title' => 'Workbook',
        'visibility' => 'enrolled',
        'downloadable' => false,
        'position' => 1,
    ]);

    Sanctum::actingAs($learner);

    // Entitled, but the admin marked it view-only: buying more changes nothing, so the code says so.
    $this->postJson("/api/v1/resources/{$resource->public_id}/download")
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'RESOURCE_NOT_DOWNLOADABLE');
});

it('gives an unauthenticated API call the standard envelope too', function (): void {
    $this->getJson('/api/v1/profile')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});
