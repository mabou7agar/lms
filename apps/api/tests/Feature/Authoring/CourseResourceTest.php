<?php

declare(strict_types=1);

use App\Contexts\Learning\Enums\EnrollmentSource;
use App\Contexts\Learning\Enums\EnrollmentStatus;
use App\Contexts\Learning\Models\Enrollment;
use App\Domains\Authoring\Enums\ResourceVisibility;
use App\Domains\Authoring\Events\CourseResourceDownloaded;
use App\Domains\Authoring\Models\CourseResource;
use App\Domains\Authoring\Models\Lesson;
use App\Domains\Authoring\Models\Section;
use App\Domains\Catalog\Models\Course;
use App\Domains\Catalog\Models\CourseTrainer;
use App\Platform\Identity\Models\User;
use App\Platform\Media\Models\MediaAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** A published course with one lesson, and an asset in the library to attach. */
function resourceFixture(): array
{
    $course = Course::factory()->published()->create();
    $section = Section::factory()->published()->create(['course_id' => $course->id]);
    $lesson = Lesson::factory()->published()->create(['section_id' => $section->id]);
    $asset = MediaAsset::factory()->create();

    return [$course, $lesson, $asset];
}

function attachResource(Course $course, MediaAsset $asset, array $overrides = []): CourseResource
{
    return CourseResource::create(array_merge([
        'course_id' => $course->id,
        'media_asset_id' => $asset->id,
        'title' => 'Workbook',
        'visibility' => ResourceVisibility::Enrolled->value,
        'downloadable' => true,
        'position' => 1,
    ], $overrides));
}

function enrol(Course $course, User $user, ?string $expiresAt = null): Enrollment
{
    return Enrollment::create([
        'user_id' => $user->id,
        'course_id' => $course->id,
        'status' => EnrollmentStatus::Active->value,
        'source' => $expiresAt === null ? EnrollmentSource::Purchase->value : EnrollmentSource::CompanySeat->value,
        'enrolled_at' => now(),
        'expires_at' => $expiresAt,
    ]);
}

// ── Attaching ────────────────────────────────────────────────────────────────────────────────────

it('lets a course instructor attach a course-level file', function (): void {
    [$course, , $asset] = resourceFixture();
    $instructor = User::factory()->create();
    CourseTrainer::create(['course_id' => $course->id, 'user_id' => $instructor->id]);
    $asset->forceFill(['created_by' => $instructor->id])->save();

    Sanctum::actingAs($instructor);

    $this->postJson("/api/v1/authoring/courses/{$course->public_id}/resources", [
        'media_id' => $asset->public_id,
        'title' => 'Course workbook',
        'description' => 'Everything in one PDF.',
    ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Course workbook')
        ->assertJsonPath('data.scope', 'course')
        ->assertJsonPath('data.visibility', 'enrolled');

    expect(CourseResource::where('course_id', $course->id)->count())->toBe(1);
});

it('lets an instructor attach a file to one lesson', function (): void {
    [$course, $lesson, $asset] = resourceFixture();
    $instructor = User::factory()->create();
    CourseTrainer::create(['course_id' => $course->id, 'user_id' => $instructor->id]);
    $asset->forceFill(['created_by' => $instructor->id])->save();

    Sanctum::actingAs($instructor);

    $this->postJson("/api/v1/authoring/courses/{$course->public_id}/resources", [
        'media_id' => $asset->public_id,
        'title' => 'Lesson slides',
        'lesson_id' => $lesson->public_id,
    ])
        ->assertCreated()
        ->assertJsonPath('data.scope', 'lesson');
});

it('refuses to attach a file to somebody else course', function (): void {
    [$course, , $asset] = resourceFixture();
    $outsider = User::factory()->create();
    $asset->forceFill(['created_by' => $outsider->id])->save();

    Sanctum::actingAs($outsider);

    $this->postJson("/api/v1/authoring/courses/{$course->public_id}/resources", [
        'media_id' => $asset->public_id,
        'title' => 'Not mine',
    ])->assertNotFound();
});

// ── Listing ──────────────────────────────────────────────────────────────────────────────────────

it('shows an enrolled learner the course files', function (): void {
    [$course, , $asset] = resourceFixture();
    attachResource($course, $asset, ['title' => 'Workbook']);
    $learner = User::factory()->create();
    enrol($course, $learner);

    Sanctum::actingAs($learner);

    $this->getJson("/api/v1/courses/{$course->public_id}/resources")
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.title', 'Workbook')
        ->assertJsonPath('data.entitled', true);
});

it('shows an unentitled visitor only the preview files', function (): void {
    [$course, , $asset] = resourceFixture();
    attachResource($course, $asset, ['title' => 'Paid workbook']);
    attachResource($course, $asset, ['title' => 'Free syllabus', 'visibility' => ResourceVisibility::Preview->value]);

    Sanctum::actingAs(User::factory()->create());

    $this->getJson("/api/v1/courses/{$course->public_id}/resources")
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.title', 'Free syllabus')
        ->assertJsonPath('data.entitled', false);
});

it('never exposes a storage key or the media id', function (): void {
    [$course, , $asset] = resourceFixture();
    attachResource($course, $asset);
    // Give the asset a recognisable storage key so its absence from the payload is meaningful.
    $asset->forceFill(['storage_key' => 'private/secret-bucket-key.pdf'])->save();
    $learner = User::factory()->create();
    enrol($course, $learner);

    Sanctum::actingAs($learner);

    $body = $this->getJson("/api/v1/courses/{$course->public_id}/resources")->assertOk()->content();

    expect($body)->not->toContain('storage_key')
        ->and($body)->not->toContain('media_asset_id')
        ->and($body)->not->toContain('playback_id')
        ->and($body)->not->toContain('secret-bucket-key');
});

it('lists the files attached to a lesson', function (): void {
    [$course, $lesson, $asset] = resourceFixture();
    attachResource($course, $asset, ['title' => 'Lesson handout', 'lesson_id' => $lesson->id]);
    attachResource($course, $asset, ['title' => 'Course-wide']);
    $learner = User::factory()->create();
    enrol($course, $learner);

    Sanctum::actingAs($learner);

    $this->getJson("/api/v1/lessons/{$lesson->public_id}/resources")
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.title', 'Lesson handout');
});

// ── Downloading ──────────────────────────────────────────────────────────────────────────────────

it('gives an entitled learner a short-lived signed url and records the download', function (): void {
    Event::fake([CourseResourceDownloaded::class]);

    [$course, , $asset] = resourceFixture();
    $resource = attachResource($course, $asset);
    $learner = User::factory()->create();
    enrol($course, $learner);

    Sanctum::actingAs($learner);

    $this->postJson("/api/v1/resources/{$resource->public_id}/download")
        ->assertOk()
        ->assertJsonStructure(['data' => ['url', 'expires_at', 'title']]);

    Event::assertDispatched(CourseResourceDownloaded::class, fn ($e): bool => $e->resourceId === $resource->id
        && $e->userId === $learner->id);
});

it('refuses a download to somebody who is not enrolled', function (): void {
    [$course, , $asset] = resourceFixture();
    $resource = attachResource($course, $asset);

    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/resources/{$resource->public_id}/download")->assertForbidden();
});

it('refuses a download once a company seat has expired', function (): void {
    [$course, , $asset] = resourceFixture();
    $resource = attachResource($course, $asset);
    $employee = User::factory()->create();
    // A company seat whose access window closed yesterday.
    enrol($course, $employee, now()->subDay()->toDateTimeString());

    Sanctum::actingAs($employee);

    $this->postJson("/api/v1/resources/{$resource->public_id}/download")->assertForbidden();
});

it('lets anyone take a preview file', function (): void {
    [$course, , $asset] = resourceFixture();
    $resource = attachResource($course, $asset, ['visibility' => ResourceVisibility::Preview->value]);

    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/v1/resources/{$resource->public_id}/download")->assertOk();
});

it('refuses a file the author marked as not downloadable', function (): void {
    [$course, , $asset] = resourceFixture();
    $resource = attachResource($course, $asset, ['downloadable' => false]);
    $learner = User::factory()->create();
    enrol($course, $learner);

    Sanctum::actingAs($learner);

    $this->postJson("/api/v1/resources/{$resource->public_id}/download")->assertForbidden();
});
